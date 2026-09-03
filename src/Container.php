<?php
// restina/Container.php

namespace Restina;

use ReflectionClass;
use ReflectionProperty;
use ReflectionMethod;
use ReflectionNamedType;
use Restina\attribute\Inject;

/**
 * 轻量级依赖注入容器
 * 
 * 支持：
 * - set/get/has 基本容器操作
 * - 闭包工厂（每次 get 时调用）
 * - 类名自动装配（反射构造函数 + 递归解析 + 缓存）
 * - #[Inject] 属性注入
 * - 循环依赖检测（get 路径 + make 路径）
 *
 * @package Restina
 */
class Container
{
    /**
     * 已注册的服务条目（实例或闭包工厂）
     */
    private array $entries = [];

    /**
     * 已解析的缓存（自动装配的类实例）
     */
    private array $resolved = [];

    /**
     * 记录已被实例化的服务 ID（包括通过 get() 和 make() 创建的）
     */
    private array $instantiated = [];

    /**
     * 正在通过 get() 自动装配的类，防止循环依赖
     */
    private array $resolving = [];

    /**
     * 正在通过 make() 创建的类，防止循环依赖
     */
    private array $making = [];

    public function __construct()
    {
    }

    /**
     * 获取服务实例
     *
     * 优先级：已解析缓存 → 已注册条目（工厂则调用） → 自动装配
     *
     * @param string $id 服务ID
     * @return mixed
     * @throws \RuntimeException 无法解析时
     */
    public function get(string $id): mixed
    {
        // 已解析的缓存直接返回（支持 null 值缓存）
        if (array_key_exists($id, $this->resolved)) {
            return $this->resolved[$id];
        }

        // 已注册的条目
        if (array_key_exists($id, $this->entries)) {
            $entry = $this->entries[$id];
            if ($entry instanceof \Closure) {
                return $entry($this);
            }
            $this->markAsInstantiated($id);
            return $entry;
        }

        // 尝试自动装配
        return $this->autowire($id);
    }

    /**
     * 检查服务是否存在（已注册、已解析、或可自动装配的类）
     */
    public function has(string $id): bool
    {
        if (array_key_exists($id, $this->entries) || array_key_exists($id, $this->resolved)) {
            return true;
        }
        // 检查是否是可以自动装配的类
        return class_exists($id) && (new ReflectionClass($id))->isInstantiable();
    }

    /**
     * 设置服务
     *
     * @param string $id 服务ID
     * @param mixed $service 实例值或闭包工厂（闭包每次 get 时调用，不缓存）
     */
    public function set(string $id, mixed $service): void
    {
        $this->entries[$id] = $service;
        // 清除可能的旧缓存和旧标记
        unset($this->resolved[$id], $this->instantiated[$id]);
        // 如果设置的是实例，标记为已实例化
        if (!($service instanceof \Closure) && !is_callable($service) && !is_array($service)) {
            $this->markAsInstantiated($id);
        }
    }

    /**
     * 通过反射创建实例并注入依赖
     */
    public function make(string $className, array $parameters = [])
    {
        // 检测 make() 路径的循环依赖
        if (isset($this->making[$className])) {
            $chain = implode(' -> ', [...array_keys($this->making), $className]);
            throw new \LogicException("make() 循环依赖: {$chain}");
        }
        $this->making[$className] = true;
        try {
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
        } finally {
            unset($this->making[$className]);
        }
    }

    /**
     * 自动装配：反射构造函数，递归解析类类型依赖，结果缓存
     */
    private function autowire(string $className): mixed
    {
        if (!class_exists($className)) {
            throw new \RuntimeException("找不到服务或类 '$className'");
        }

        $reflection = new ReflectionClass($className);
        if (!$reflection->isInstantiable()) {
            throw new \RuntimeException("类 '$className' 无法实例化");
        }

        // 检测 get() 路径的循环依赖
        if (isset($this->resolving[$className])) {
            $chain = implode(' -> ', [...array_keys($this->resolving), $className]);
            throw new \LogicException("循环依赖: {$chain}");
        }
        $this->resolving[$className] = true;

        try {
            $constructor = $reflection->getConstructor();
            $args = [];
            if ($constructor) {
                $args = $this->resolveParameters($constructor, []);
            }
            $instance = $reflection->newInstanceArgs($args);
            $this->injectProperties($instance);
        } finally {
            unset($this->resolving[$className]);
        }

        // 缓存结果
        $this->resolved[$className] = $instance;
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

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                // 如果是类类型，从容器获取
                try {
                    $resolved[] = $this->get($type->getName());
                } catch (\Throwable $e) {
                    // 解析失败但参数可选时，使用默认值
                    if ($parameter->isDefaultValueAvailable() || $parameter->allowsNull()) {
                        $resolved[] = $parameter->isDefaultValueAvailable()
                            ? $parameter->getDefaultValue()
                            : null;
                    } else {
                        throw $e;
                    }
                }
            } elseif ($parameter->isDefaultValueAvailable()) {
                // 如果有默认值，使用默认值
                $resolved[] = $parameter->getDefaultValue();
            } else {
                // 如果无法解析，抛出异常
                throw new \InvalidArgumentException(
                    "无法解析参数 {$parameter->getName()}（{$constructor->getDeclaringClass()->getName()}）"
                );
            }
        }

        return $resolved;
    }

    /**
     * 注入属性
     * @param object $instance
     */
    public function injectProperties(object $instance): void
    {
        $reflection = new ReflectionClass($instance);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED | ReflectionProperty::IS_PRIVATE);
        foreach ($properties as $property) {
            $attributes = $property->getAttributes(Inject::class);
            if (empty($attributes)) {
                continue; // 没有注解，跳过
            }
            // 获取 Inject 属性的参数
            $attribute = $attributes[0];
            $arguments = $attribute->getArguments();
            $id = $arguments['id'] ?? $arguments[0] ?? null; // 兼容命名参数和位置参数
            // 如果 Attribute 没有指定 ID，则尝试从属性类型推断
            if ($id === null) {
                $type = $property->getType();
                // 检查是否存在类型声明，且不是内置类型 (int, string, array 等)
                if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    $className = $reflection->getName();
                    $propName = $property->getName();
                    throw new \InvalidArgumentException("无法自动解析类 {$className} 中属性 \${$propName} 的注入。请在 #[Inject('ClassName')] 中指定类名或添加有效的类型提示。");
                }
                $id = $type->getName();
            }
            if ($id) {
                $property->setValue($instance, $this->get($id));
            }
        }
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
}
