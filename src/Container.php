<?php
// restina/Container.php

namespace Restina;

use DI\Container as PHPDIContainer;
use DI\DependencyException;
use DI\NotFoundException;
use ReflectionClass;
use ReflectionProperty;
use ReflectionMethod;

class Container
{
    private PHPDIContainer $container;

    /**
     * 记录已被实例化的服务 ID（包括通过 get() 和 make() 创建的）
     */
    private array $instantiated = [];

    public function __construct(?PHPDIContainer $container = null)
    {
        $this->container = $container ?: new PHPDIContainer();
    }

    /**
     * 获取服务实例
     *
     * @param string $id 服务ID
     * @return mixed
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function get(string $id)
    {
        $instance = $this->container->get($id);
        $this->markAsInstantiated($id);
        return $instance;
    }

    /**
     * 检查服务是否存在
     */
    public function has(string $id): bool
    {
        return $this->container->has($id);
    }

    /**
     * 设置服务
     */
    public function set(string $id, mixed $service): void
    {
        $this->container->set($id, $service);
        // 如果设置的是实例，则标记为已实例化
        if (!is_callable($service) && !is_array($service)) {
            $this->markAsInstantiated($id);
        }
    }

    /**
     * 通过反射创建实例并注入依赖
     */
    public function make(string $className, array $parameters = [])
    {
        $reflection = new ReflectionClass($className);
        // 获取构造函数参数
        $constructor = $reflection->getConstructor();
        $constructorArgs = [];
        if ($constructor) {
            $constructorArgs = $this->resolveParameters($constructor, $parameters);
        }
        // 创建实例
        $instance = $reflection->newInstanceArgs($constructorArgs);
        // 注入属性
        $this->injectProperties($instance);
        // 标记为已实例化
        $this->markAsInstantiated($className);
        return $instance;
    }

    /**
     * 解析构造函数参数
     */
    private function resolveParameters(ReflectionMethod $constructor, array $providedParameters): array
    {
        $resolved = [];

        foreach ($constructor->getParameters() as $index => $parameter) {
            $type = $parameter->getType();

            // 如果提供了参数，优先使用提供的参数
            if (isset($providedParameters[$index])) {
                $resolved[] = $providedParameters[$index];
                continue;
            }

            if ($type && !$type->isBuiltin()) {
                // 如果是类类型，从容器获取
                $resolved[] = $this->get($type->getName());
            } elseif ($parameter->isDefaultValueAvailable()) {
                // 如果有默认值，使用默认值
                $resolved[] = $parameter->getDefaultValue();
            } else {
                // 如果无法解析，抛出异常
                throw new \InvalidArgumentException(
                    "Cannot resolve parameter {$parameter->getName()} of {$constructor->getDeclaringClass()->getName()}"
                );
            }
        }

        return $resolved;
    }

    /**
     * 注入属性
     */
    public function injectProperties(object $instance): void
    {
        $reflection = new ReflectionClass($instance);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PRIVATE);
        foreach ($properties as $property) {
            if ($this->shouldInjectProperty($property)) {
                $this->injectProperty($instance, $property);
            }
        }
    }

    /**
     * 判断是否应该注入属性
     */
    private function shouldInjectProperty(ReflectionProperty $property): bool
    {
        $docComment = $property->getDocComment();

        // 检查是否有 @inject 注解
        return $docComment && strpos($docComment, '@inject') !== false;
    }

    /**
     * 注入单个属性
     */
    private function injectProperty(object $instance, ReflectionProperty $property): void
    {
        // 在 PHP 8.1+ 中，不再需要调用 setAccessible()
        // ReflectionProperty::setValue() 方法会自动处理访问权限

        $type = $property->getType();
        if (!$type || $type->isBuiltin()) {
            return;
        }

        $serviceName = $type->getName();
        $service = $this->get($serviceName);

        // 使用 setValue 方法，无需调用 setAccessible()
        $property->setValue($instance, $service);
    }

    /**
     * 标记服务为已实例化
     */
    private function markAsInstantiated(string $id): void
    {
        $this->instantiated[$id] = true;
    }

    /**
     * 判断服务是否已被实例化（通过 get() 或 make()）
     */
    public function isInstantiated(string $id): bool
    {
        return isset($this->instantiated[$id]);
    }

    /**
     * 清除已实例化的标记
     */
    public function clearInstantiated(string $id): void
    {
        unset($this->instantiated[$id]);
    }

    /**
     * 获取底层容器
     */
    public function getRawContainer(): PHPDIContainer
    {
        return $this->container;
    }
}
