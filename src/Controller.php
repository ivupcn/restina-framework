<?php
// restina/Controller.php

namespace Restina;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use Restina\Validator;
use Restina\Hook;
use Restina\Container;

class Controller
{
    private Container $diContainer;

    public function __construct(
        private App $app,
        ?Container $diContainer = null
    ) {
        $this->diContainer = $diContainer ?: new Container();
    }

    /**
     * 加载路由信息
     */
    public function loadRoutes(array $routes): void
    {
        // 设置路由缓存（如果非调试模式）
        $this->setupRouteCaching();

        // 批量注册路由
        $this->registerRoutes($routes);
    }

    /**
     * 设置路由缓存
     */
    private function setupRouteCaching(): void
    {
        if (!$this->app->isDebugMode()) {
            $routeCollector = $this->app->getSlimApp()->getRouteCollector();
            $cacheFile = $this->app->getCachePath() . DIRECTORY_SEPARATOR . 'routeCollector.cache';

            // 确保缓存目录存在
            $cacheDir = dirname($cacheFile);
            if (!is_dir($cacheDir)) {
                mkdir($cacheDir, 0755, true);
            }

            $routeCollector->setCacheFile($cacheFile);
        }
    }

    /**
     * 批量注册路由
     */
    private function registerRoutes(array $routes): void
    {
        $startTime = microtime(true);
        $routeCount = count($routes);

        foreach ($routes as $route) {
            $this->registerSingleRoute($route);
        }

        if ($this->app->isDebugMode()) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            error_log("[PERFORMANCE] Registered {$routeCount} routes in {$duration}ms");
        }
    }

    /**
     * 注册单个路由
     */
    private function registerSingleRoute(array $route): void
    {
        $class = $route['class'];
        $methodName = $route['method'];
        $methodType = $route['httpMethod'];
        $path = $route['path'];
        $docComment = $route['docComment'];
        // 使用预创建的处理器（避免重复闭包创建）
        $handler = $this->getOrCreateRouteHandler($class, $methodName, $docComment);
        $this->app->getSlimApp()->map([$methodType], $path, $handler);
    }

    /**
     * 获取或创建路由处理器（带缓存）
     */
    private function getOrCreateRouteHandler(string $class, string $methodName, string $docComment): callable | null
    {
        static $handlers = [];
        $key = $class . '::' . $methodName;

        if (!isset($handlers[$key])) {
            $handlers[$key] = function (Request $request, Response $response, array $args) use ($class, $methodName, $docComment): Response | null {
                return $this->handleRouteRequest($request, $response, $args, $class, $methodName, $docComment);
            };
        }
        return $handlers[$key];
    }

    /**
     * 处理路由请求
     */
    private function handleRouteRequest(
        Request $request,
        Response $response,
        array $args,
        string $class,
        string $methodName,
        string $docComment
    ): Response {
        try {
            // 缓存 Reflection 实例以提高性能
            static $reflectionCache = [];
            $cacheKey = $class . '::' . $methodName;

            if (!isset($reflectionCache[$cacheKey])) {
                $reflectionCache[$cacheKey] = [
                    'instance' => $this->getControllerInstance($class),
                    'method' => (new ReflectionClass($class))->getMethod($methodName)
                ];
            }

            $controllerInstance = $reflectionCache[$cacheKey]['instance'];
            $method = $reflectionCache[$cacheKey]['method'];

            // 将 app 实例附加到请求对象
            $request = $request->withAttribute('app', $this->app);

            // 执行请求处理钩子
            $this->executeRequestHooks($request, $response, $args, $controllerInstance, $method);

            // 构建参数并调用控制器方法
            $parameters = $this->buildMethodParameters($method, $request, $response, $args, $docComment);
            $result = $method->invokeArgs($controllerInstance, $parameters);

            // 处理响应
            return $this->processResponse($request, $response, $result, $controllerInstance, $method);
        } catch (\InvalidArgumentException $e) {
            return $this->handleValidationException($request, $response, $e);
        } catch (\Exception $e) {
            return $this->handleGeneralException($request, $response, $e);
        }
    }

    /**
     * 执行请求处理钩子
     */
    private function executeRequestHooks(
        Request $request,
        Response $response,
        array $args,
        object $controllerInstance,
        ReflectionMethod $method
    ): void {
        Hook::doAction('request.before_handle', [
            'request' => $request,
            'response' => $response,
            'args' => $args,
            'controller' => $controllerInstance,
            'method' => $method
        ]);

        Hook::doAction('controller.before_execute', [
            'controller' => $controllerInstance,
            'method' => $method,
            'request' => $request,
            'args' => $args
        ]);
    }

    /**
     * 处理响应
     */
    private function processResponse(
        Request $request,
        Response $response,
        mixed $result,
        object $controllerInstance,
        ReflectionMethod $method
    ): Response {
        // 检查是否返回了Response对象
        if ($result instanceof Response) {
            return $result;
        }

        // 应用响应数据过滤器
        $result = Hook::applyFilters('controller.result', $result, $method, $request);

        // 触发控制器执行后钩子
        Hook::doAction('controller.after_execute', [
            'controller' => $controllerInstance,
            'method' => $method,
            'request' => $request,
            'result' => $result
        ]);

        // 自动 JSON 响应
        $response->getBody()->write(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // 触发请求处理后钩子
        Hook::doAction('request.after_handle', [
            'request' => $request,
            'response' => $response,
            'result' => $result
        ]);

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(is_array($result) && isset($result['error']) ? 400 : 200);
    }

    /**
     * 处理验证异常
     */
    private function handleValidationException(Request $request, Response $response, \InvalidArgumentException $e): Response
    {
        Hook::doAction('parameter.validate_error', [
            'error' => $e,
            'request' => $request,
            'response' => $response
        ]);

        $response->getBody()->write(json_encode([
            'error' => 'Validation Error',
            'message' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(400);
    }

    /**
     * 处理通用异常
     */
    private function handleGeneralException(Request $request, Response $response, \Exception $e): Response
    {
        Hook::doAction('request.error', [
            'error' => $e,
            'request' => $request,
            'response' => $response
        ]);

        $response->getBody()->write(json_encode([
            'error' => 'Internal Error',
            'message' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(500);
    }

    private function getControllerInstance(string $className)
    {
        // 使用依赖注入容器创建实例
        if ($this->diContainer->isInstantiated($className)) {
            return $this->diContainer->get($className);
        }
        return $this->diContainer->make($className);
    }

    private function buildMethodParameters(ReflectionMethod $method, Request $request, Response $response, array $routeArgs, string $docComment): array
    {
        $parameters = [];
        $queryParams = $request->getQueryParams();
        $parsedBody = $request->getParsedBody();

        // 触发参数验证前钩子
        Hook::doAction('parameter.validate_before', [
            'method' => $method,
            'request' => $request,
            'routeArgs' => $routeArgs
        ]);

        foreach ($method->getParameters() as $param) {
            $paramName = $param->getName();
            $type = $param->getType();

            // 检查是否为特殊对象参数（Request/Response）
            if ($type instanceof ReflectionNamedType) {
                $typeName = $type->getName();

                if ($typeName === Request::class) {
                    $parameters[] = $request;
                    continue;
                }

                if ($typeName === Response::class) {
                    $parameters[] = $response;
                    continue;
                }
            }

            // 优先从路由参数获取
            if (isset($routeArgs[$paramName])) {
                $value = $this->convertParameter($routeArgs[$paramName], $type);
                $value = Validator::validate($value, $docComment, $paramName);
                $parameters[] = $value;
                continue;
            }

            // 从查询参数获取
            if (isset($queryParams[$paramName])) {
                $value = $this->convertParameter($queryParams[$paramName], $type);
                $value = Validator::validate($value, $docComment, $paramName);
                $parameters[] = $value;
                continue;
            }

            // 从请求体获取
            if (is_array($parsedBody) && isset($parsedBody[$paramName])) {
                $value = $this->convertParameter($parsedBody[$paramName], $type);
                $value = Validator::validate($value, $docComment, $paramName);
                $parameters[] = $value;
                continue;
            }

            // 检查特殊参数名
            if ($type && $type->getName() === 'array' && in_array($paramName, ['payload', 'data', 'body'], true)) {
                $value = is_array($parsedBody) ? $parsedBody : [];
                $value = Validator::validate($value, $docComment, $paramName);
                $parameters[] = $value;
                continue;
            }

            // 使用默认值或抛出异常
            if ($param->isDefaultValueAvailable()) {
                $defaultValue = $param->getDefaultValue();
                $value = Validator::validate($defaultValue, $docComment, $paramName);
                $parameters[] = $value;
            } else {
                throw new \InvalidArgumentException("Missing required parameter: { $paramName}");
            }
        }

        // 触发参数验证后钩子
        Hook::doAction('parameter.validate_after', [
            'method' => $method,
            'parameters' => $parameters
        ]);

        return $parameters;
    }

    private function convertParameter(mixed $value, ?ReflectionType $type): mixed
    {
        if (!$type instanceof ReflectionNamedType) {
            return $value;
        }

        $typeName = $type->getName();
        switch ($typeName) {
            case 'int':
                return (int) $value;
            case 'float':
            case 'double':
                return (float) $value;
            case 'bool':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            case 'string':
                return (string) $value;
            default:
                return $value;
        }
    }
}
