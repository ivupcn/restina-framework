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

/**
 * 控制器加载.
 */
class Controller
{
    /**
     * 容器.
     */
    private Container $diContainer;

    /**
     * 构造函数.
     */
    public function __construct(private App $app, ?Container $diContainer = null)
    {
        $this->diContainer = $diContainer ?: new Container();
    }

    /**
     * 加载路由
     *
     * @param array $routes
     * @return void
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
     *
     * @param array $routes
     * @return void
     */
    private function registerRoutes(array $routes): void
    {
        $startTime = microtime(true);
        $routeCount = count($routes);

        $this->addDefaultRouteIfMissing($routes);

        foreach ($routes as $class => $route) {

            $this->registerSingleRoute($class, $route, $route['methodName'], $route['httpMethods'], $route['route']);
        }

        if ($this->app->isDebugMode()) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            error_log("[PERFORMANCE] Registered {$routeCount} routes in {$duration}ms");
        }
    }

    /**
     * 添加默认路由（当没有根路径路由时）
     *
     * @param array $routes
     * @return void
     */
    private function addDefaultRouteIfMissing(array $routes): void
    {
        $hasDefaultRoute = false;
        foreach ($routes as $class => $route) {
            if ($route['route'] === '/') {
                $hasDefaultRoute = true;
                break;
            }
        }

        if (!$hasDefaultRoute) {
            $this->app->getSlimApp()->get('/', function (Request $request, Response $response) {
                $response->getBody()->write(json_encode([
                    'error' => 'Tips',
                    'message' => 'default route missing',
                ], JSON_UNESCAPED_UNICODE));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
            });
        }
    }

    /**
     * 注册单个路由
     *
     * @param string $className
     * @param array $routeInfo
     * @param string $methodName
     * @param array $httpMethods
     * @param string $route
     * @return void
     */
    private function registerSingleRoute(string $className, array $routeInfo, string $methodName, array $httpMethods, string $route): void
    {
        // 使用预创建的处理器（避免重复闭包创建）
        $handler = $this->getOrCreateRouteHandler($className, $methodName, $routeInfo);
        $this->app->getSlimApp()->map($httpMethods, $route, $handler);
    }

    /**
     * 获取或创建路由处理器
     *
     * @param string $className
     * @param string $methodName
     * @param array $routeInfo
     * @return callable
     */
    private function getOrCreateRouteHandler(string $className, string $methodName, array $routeInfo): callable | null
    {
        static $handlers = [];
        $key = $className . '::' . $methodName;

        if (!isset($handlers[$key])) {
            $handlers[$key] = function (Request $request, Response $response, array $args) use ($className, $methodName, $routeInfo): Response | null {
                return $this->handleRouteRequest($request, $response, $args, $className, $methodName, $routeInfo);
            };
        }
        return $handlers[$key];
    }

    /**
     * 处理路由请求
     *
     * @param Request $request
     * @param Response $response
     * @param array $args
     * @param string $class
     * @param string $methodName
     * @param array $routeInfo
     * @return Response
     */
    private function handleRouteRequest(
        Request $request,
        Response $response,
        array $args,
        string $class,
        string $methodName,
        array $routeInfo
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

            // JWT认证中间件
            $request = $this->checkJWT($request, $routeInfo);

            // 执行请求处理钩子
            $this->executeRequestHooks($request, $response, $args, $controllerInstance, $method);

            // 构建参数
            $parameters = $this->buildMethodParameters($method, $request, $response, $args, $routeInfo);

            // 检查权限
            $this->checkPermission($routeInfo, $request, $class, $methodName, $args);

            // 调用控制器方法
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
     *
     * @param Request $request
     * @param Response $response
     * @param array $args
     * @param object $controllerInstance
     * @param ReflectionMethod $method
     * @return void
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
     *
     * @param Request $request
     * @param Response $response
     * @param mixed $result
     * @param object $controllerInstance
     * @param ReflectionMethod $method
     * @return Response
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
     * @param Request $request
     * @param Response $response
     * @param \InvalidArgumentException $e
     * @return Response
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
     * 处理一般异常
     * @param Request $request
     * @param Response $response
     * @param \Exception $e
     * @return Response
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

        $statusCode = $e->getCode();
        if (!is_int($statusCode) || $statusCode < 100 || $statusCode >= 600) {
            $statusCode = 500;
        }

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($statusCode);
    }

    /**
     * 获取或创建控制器实例
     *
     * @param string $className
     * @return object
     */
    private function getControllerInstance(string $className)
    {
        // 使用依赖注入容器创建实例
        if ($this->diContainer->isInstantiated($className)) {
            return $this->diContainer->get($className);
        }
        return $this->diContainer->make($className);
    }

    /**
     * 检查JWT
     *
     * @param Request $request
     * @param array $routeInfo
     * @return Request
     */
    private function checkJWT(Request $request, $routeInfo): Request
    {
        $jwt = $routeInfo['jwt'] ?? true;
        // 如果不需要JWT验证，直接返回
        if (!$jwt) {
            return $request;
        }
        // 获取Authorization头
        $authHeader = $request->getHeaderLine('Authorization');
        if (!$authHeader) {
            throw new \Exception('Authorization header required', 401);
        }
        // 使用正则表达式匹配 Bearer token
        if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            throw new \Exception('Authorization error', 401);
        }
        // 获取token
        $token = trim($matches[1]);
        // 验证并获取 token 数据（允许过期）
        $tokenData = $this->app->jwt->getTokenDataAllowExpired($token);
        if (!isset($tokenData['id'])) {
            throw new \Exception('Invalid token payload', 401);
        }
        // 检查必要字段是否存在
        if (!isset($tokenData['exp'])) {
            throw new \Exception('Token missing expiration time', 401);
        }
        // 检查是否过期
        $isExpired = $tokenData['exp'] < time();
        if ($isExpired) {
            // 检查刷新令牌是否已过期
            // 如果refresh_exp不存在，视为刷新已过期
            if (!isset($tokenData['refresh_exp'])) {
                throw new \Exception('Token has expired and no refresh information available', 401);
            }
            $isRefreshExpired = $tokenData['refresh_exp'] < time();
            if ($isRefreshExpired) {
                throw new \Exception('Token has expired and refresh time exceeded', 401);
            }
            // 自动刷新Token逻辑
            $autoRefresh = $routeInfo['autoRefreshToken'] ?? false;
            if ($autoRefresh) {
                try {
                    // 刷新Token并更新请求头
                    $newToken = $this->app->jwt->refreshToken($token);
                    $request = $request->withHeader('Authorization', 'Bearer ' . $newToken);
                    $tokenData = $this->app->jwt->getTokenData($newToken);
                    // 将新的Token信息附加到请求属性中，供后续使用
                    $request = $request->withAttribute('tokenRefreshed', true)->withAttribute('newToken', $newToken);
                } catch (\Exception $e) {
                    throw new \Exception('Token has expired and refresh failed: ' . $e->getMessage(), 401);
                }
            } else {
                throw new \Exception('Token has expired', 401);
            }
        }
        // 将用户信息附加到请求属性中，供后续使用
        $request = $request->withAttribute('requestUser', null)->withAttribute('requestUserId', intval($tokenData['id']));
        // 触发JWT认证成功钩子，允许开发者在此处进行额外处理（例如加载用户信息）
        $request = Hook::applyFilters('jwt.user', $request);
        return $request;
    }

    /**
     * 检查权限
     *
     * @param array $routeInfo
     * @param Request $request
     * @param string $className
     * @param string $methodName
     * @return void
     */
    private function checkPermission(array $routeInfo, Request $request, string $className, string $methodName, array $args): void
    {
        $permission = $routeInfo['permission'] ?? null;
        $code = $routeInfo['code'] ?? null;
        if ($permission && $code) {
            $checkAuthResult = Hook::applyFilters('permission.check', true, $code, $request, $className, $methodName, $args);
            if (!$checkAuthResult) {
                throw new \Exception('Permission check failed', 403);
            }
        }
    }

    /**
     * @param ReflectionMethod $method
     * @param Request $request
     * @param Response $response
     * @param array $routedParams
     * @return array
     */
    private function buildMethodParameters(ReflectionMethod $method, Request $request, Response $response, array $routeArgs, array $routeInfo): array
    {
        $parameters = [];
        $paramRules = $this->extractParamRules($routeInfo['params'] ?? []);
        $requestData = $this->prepareRequestData($request);
        foreach ($method->getParameters() as $param) {
            $paramValue = $this->getParameterValue($param, $request, $response, $routeArgs, $requestData, $paramRules);
            $parameters[] = $paramValue;
        }
        return $parameters;
    }

    /**
     * 从参数注释中提取验证规则
     *
     * @param array $params 参数注释数组
     * @return array 规则数组，格式为 ['paramName' => 'rule1|rule2']
     */
    private function extractParamRules(array $params): array
    {
        $rules = [];
        foreach ($params as $param) {
            $rules[$param['field']] = $param['rules'] ?? '';
        }
        return $rules;
    }

    /**
     * 准备请求数据
     *
     * @param Request $request 请求对象
     * @return array 处理后的请求数据
     */
    private function prepareRequestData(Request $request): array
    {
        $inputStream = $request->getBody()->__toString();
        $parsedData = [];

        if (!empty($inputStream) && in_array($request->getMethod(), ['PUT', 'PATCH', 'DELETE', 'POST'])) {
            $contentType = $request->getHeaderLine('Content-Type');

            if (str_contains($contentType, 'application/json')) {
                $parsedData = json_decode($inputStream, true) ?: [];
            } elseif (str_contains($contentType, 'application/x-www-form-urlencoded')) {
                parse_str($inputStream, $parsedData);
            } else {
                $parsedData = json_decode($inputStream, true) ?: [];
            }
        }

        return [
            'query' => $request->getQueryParams(),
            'parsedBody' => $request->getParsedBody(),
            'parsedData' => $parsedData
        ];
    }

    /**
     * 获取参数值并进行验证
     * 
     * @param \ReflectionParameter $param
     * @param Request $request
     * @param Response $response
     * @param array $routeArgs
     * @param array $requestData
     * @param array $paramData
     * @return mixed
     */
    private function getParameterValue(\ReflectionParameter $param, Request $request, Response $response, array $routeArgs, array $requestData, array $paramRules): mixed
    {
        $paramName = $param->getName();
        $type = $param->getType();

        // 特殊类型处理
        if ($type instanceof ReflectionNamedType) {
            $typeName = $type->getName();
            if ($typeName === Request::class) return $request;
            if ($typeName === Response::class) return $response;
        }

        // 尝试从不同来源获取参数值
        $value = $this->findParameterValue($paramName, $request, $routeArgs, $requestData);

        // 验证参数
        return $this->validateParameter($param, $value, $paramRules);
    }

    /**
     * 按照优先级查找参数值
     *
     * @param string $paramName 参数名称
     * @param Request $request 请求对象
     * @param array $routeArgs 路由参数
     * @param array $requestData 处理后的请求数据
     * @return mixed 参数值
     */
    private function findParameterValue(string $paramName, Request $request, array $routeArgs, array $requestData): mixed
    {
        // 定义参数查找顺序
        $sources = [
            // 路由参数优先级最高
            fn() => $routeArgs[$paramName] ?? null,
            // PUT/PATCH/DELETE 请求体数据
            fn() => in_array($request->getMethod(), ['PUT', 'PATCH', 'DELETE'])
                ? ($requestData['parsedData'][$paramName] ?? null)
                : null,
            // POST 请求体数据
            fn() => $requestData['parsedBody'][$paramName] ?? null,
            // 查询参数
            fn() => $requestData['query'][$paramName] ?? null,
            // 特殊参数处理
            fn() => $this->getSpecialParameter($paramName, $request, $requestData)
        ];

        foreach ($sources as $source) {
            $value = $source();
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * 获取特殊参数
     *
     * @param string $paramName
     * @param Request $request
     * @param array $requestData
     * @return mixed
     */
    private function getSpecialParameter(string $paramName, Request $request, array $requestData): mixed
    {
        if (in_array($paramName, ['payload', 'data', 'body'], true)) {
            if (in_array($request->getMethod(), ['PUT', 'PATCH', 'DELETE']) && !empty($requestData['parsedData'])) {
                return $requestData['parsedData'];
            }
            return is_array($requestData['parsedBody']) ? $requestData['parsedBody'] : [];
        }
        return null;
    }

    /**
     * 验证参数值
     * 
     * @param \ReflectionParameter $param
     * @param mixed $value
     * @param array $paramRules
     * @return mixed
     */
    private function validateParameter(\ReflectionParameter $param, mixed $value, array $paramRules): mixed
    {
        $paramName = $param->getName();

        if (isset($paramRules[$paramName])) {
            if ($value !== null) {
                return Validator::validate($value, $paramRules[$paramName], $paramName);
            } elseif ($param->isDefaultValueAvailable()) {
                $defaultValue = $param->getDefaultValue();
                return Validator::validate($defaultValue, $paramRules[$paramName], $paramName);
            } else {
                throw new \InvalidArgumentException("Missing required parameter: {$paramName}");
            }
        }

        // 类型转换
        $type = $param->getType();
        if ($value !== null && $type instanceof ReflectionNamedType) {
            return $this->convertParameter($value, $type);
        }

        return $value ?? ($param->isDefaultValueAvailable() ? $param->getDefaultValue() : null);
    }

    /**
     * 类型转换
     * @param mixed $value
     * @param ReflectionType|null $type
     * @return mixed
     * @throws ReflectionException
     */
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
