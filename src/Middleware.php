<?php
// restina/MiddlewareManager.php

namespace Restina;

use Psr\Http\Server\MiddlewareInterface;
use Slim\App as SlimApp;

class Middleware
{
    private SlimApp $slimApp;
    private Container $container;
    private bool $isDebugMode;
    private App $app;

    public function __construct(SlimApp $slimApp, Container $container, bool $isDebugMode, App $app)
    {
        $this->slimApp = $slimApp;
        $this->container = $container;
        $this->isDebugMode = $isDebugMode;
        $this->app = $app;
    }

    /**
     * 注册所有中间件
     */
    public function registerMiddlewares(string $appPath): self
    {
        // 添加 Body 解析中间件
        $this->slimApp->addBodyParsingMiddleware();

        $middlewarePath = $appPath . DIRECTORY_SEPARATOR . 'middlewares.php';
        if (file_exists($middlewarePath)) {
            $middlewares = require_once $middlewarePath;

            if (is_array($middlewares)) {
                foreach ($middlewares as $middleware) {
                    $this->registerMiddleware($middleware);
                }
            }
        }

        // 添加错误处理中间件
        $this->slimApp->addErrorMiddleware(!$this->isDebugMode, true, true);

        return $this;
    }

    /**
     * 注册单个中间件
     */
    private function registerMiddleware($middleware): void
    {
        try {
            $instance = $this->resolveMiddlewareInstance($middleware);
            if ($instance !== null) {
                $this->slimApp->add($instance);
            }
        } catch (\Exception $e) {
            error_log(sprintf('Failed to register middleware: %s', $e->getMessage()));

            if ($this->isDebugMode) {
                throw $e;
            }
        }
    }

    /**
     * 解析中间件实例
     */
    private function resolveMiddlewareInstance($middleware)
    {
        if (is_string($middleware)) {
            return $this->createStringMiddleware($middleware);
        } elseif (is_callable($middleware)) {
            return $middleware;
        } elseif (is_array($middleware)) {
            return $this->createArrayMiddleware($middleware);
        } elseif (is_object($middleware)) {
            return $middleware;
        }

        throw new \InvalidArgumentException(
            sprintf('Unsupported middleware type: %s', gettype($middleware))
        );
    }

    /**
     * 处理字符串类型的中间件
     */
    private function createStringMiddleware(string $middleware): object
    {
        if ($this->container->isInstantiated($middleware)) {
            return $this->container->get($middleware);
        }

        // 检查是否为类名
        if (class_exists($middleware)) {
            $reflection = new \ReflectionClass($middleware);

            // 检查是否实现了中间件接口
            if ($this->isValidMiddlewareClass($reflection)) {
                // 尝试通过容器解析构造函数参数
                $constructorParams = $this->resolveConstructorParameters($reflection);
                return $reflection->newInstanceArgs($constructorParams);
            }

            throw new \InvalidArgumentException(
                sprintf('Middleware class %s does not implement required interface', $middleware)
            );
        }

        throw new \InvalidArgumentException(
            sprintf('Middleware class %s does not exist', $middleware)
        );
    }

    /**
     * 处理数组类型的中间件
     */
    private function createArrayMiddleware(array $middleware): object
    {
        $className = $middleware[0] ?? null;
        $params = $middleware[1] ?? [];

        if (!is_string($className) || !class_exists($className)) {
            throw new \InvalidArgumentException(
                sprintf('Middleware class %s does not exist', $className)
            );
        }

        if ($this->container->isInstantiated($className)) {
            return $this->container->get($className);
        }

        $reflection = new \ReflectionClass($className);

        // 检查是否为有效的中间件类
        if (!$this->isValidMiddlewareClass($reflection)) {
            throw new \InvalidArgumentException(
                sprintf('Middleware class %s does not implement required interface', $className)
            );
        }

        // 合并传入的参数和容器解析的参数
        $constructorParams = $this->mergeParameters($reflection, $params);
        return $reflection->newInstanceArgs($constructorParams);
    }

    /**
     * 检查是否为有效的中间件类
     */
    private function isValidMiddlewareClass(\ReflectionClass $reflection): bool
    {
        // 检查是否实现了 PSR-15 中间件接口
        return $reflection->implementsInterface(MiddlewareInterface::class);
    }

    /**
     * 解析构造函数参数
     */
    private function resolveConstructorParameters(\ReflectionClass $reflection): array
    {
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return [];
        }

        $params = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type !== null && !$type->isBuiltin()) {
                $typeName = $type->getName();

                // 如果类型在容器中已注册，从容器获取
                if ($this->container->isInstantiated($typeName)) {
                    $params[] = $this->container->get($typeName);
                }
                // 如果是 App 类型，传递当前实例
                elseif ($typeName === App::class || is_subclass_of($typeName, App::class)) {
                    $params[] = $this->app;
                }
                // 如果无法解析，使用默认值或抛出异常
                elseif ($parameter->isDefaultValueAvailable()) {
                    $params[] = $parameter->getDefaultValue();
                } else {
                    throw new \RuntimeException(
                        sprintf(
                            'Cannot resolve parameter %s for middleware %s',
                            $parameter->getName(),
                            $reflection->getName()
                        )
                    );
                }
            } else {
                // 基本类型参数处理
                if ($parameter->isDefaultValueAvailable()) {
                    $params[] = $parameter->getDefaultValue();
                } else {
                    throw new \RuntimeException(
                        sprintf(
                            'Parameter %s has no default value and cannot be resolved',
                            $parameter->getName()
                        )
                    );
                }
            }
        }

        return $params;
    }

    /**
     * 合并构造函数参数
     */
    private function mergeParameters(\ReflectionClass $reflection, array $providedParams): array
    {
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return [];
        }

        $resolvedParams = $this->resolveConstructorParameters($reflection);

        // 根据参数位置合并提供的参数
        foreach ($providedParams as $index => $value) {
            $resolvedParams[$index] = $value;
        }

        return $resolvedParams;
    }
}
