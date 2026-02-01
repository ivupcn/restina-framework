<?php
// restina/Hook.php

namespace Restina;

class Hook
{
    private static array $actions = [];
    private static array $filters = [];
    private static array $config = [];
    private static bool $initialized = false; // 添加初始化标志

    /**
     * 添加动作钩子
     */
    public static function addAction(string $hook, callable $callback, int $priority = 10): void
    {
        if (!isset(self::$actions[$hook])) {
            self::$actions[$hook] = [];
        }

        // 防止重复注册相同的回调
        $callbackHash = self::getCallbackHash($callback);
        foreach (self::$actions[$hook] as $existing) {
            if (self::getCallbackHash($existing['callback']) === $callbackHash) {
                return; // 已存在，不再注册
            }
        }

        self::$actions[$hook][] = [
            'callback' => $callback,
            'priority' => $priority
        ];

        // 按优先级排序
        usort(self::$actions[$hook], function ($a, $b) {
            return $b['priority'] <=> $a['priority'];
        });
    }

    /**
     * 生成回调函数的哈希值
     */
    private static function getCallbackHash(callable $callback): string
    {
        if (is_array($callback)) {
            if (is_object($callback[0])) {
                return spl_object_hash($callback[0]) . '::' . $callback[1];
            }
            return $callback[0] . '::' . $callback[1];
        } elseif (is_string($callback)) {
            return $callback;
        } elseif (is_object($callback)) {
            return spl_object_hash($callback);
        }
        return serialize($callback);
    }

    /**
     * 执行动作
     */
    public static function doAction(string $hook, ...$args): void
    {
        if (isset(self::$actions[$hook])) {
            foreach (self::$actions[$hook] as $action) {
                call_user_func_array($action['callback'], $args);
            }
        }
    }

    /**
     * 添加过滤器钩子
     */
    public static function addFilter(string $hook, callable $callback, int $priority = 10): void
    {
        if (!isset(self::$filters[$hook])) {
            self::$filters[$hook] = [];
        }

        // 防止重复注册相同的回调
        $callbackHash = self::getCallbackHash($callback);
        foreach (self::$filters[$hook] as $existing) {
            if (self::getCallbackHash($existing['callback']) === $callbackHash) {
                return; // 已存在，不再注册
            }
        }

        self::$filters[$hook][] = [
            'callback' => $callback,
            'priority' => $priority
        ];

        // 按优先级排序
        usort(self::$filters[$hook], function ($a, $b) {
            return $b['priority'] <=> $a['priority'];
        });
    }

    /**
     * 应用过滤器
     */
    public static function applyFilters(string $hook, mixed $value, ...$args): mixed
    {
        if (isset(self::$filters[$hook])) {
            foreach (self::$filters[$hook] as $filter) {
                $value = call_user_func_array($filter['callback'], array_merge([$value], $args));
            }
        }
        return $value;
    }

    /**
     * 移除动作钩子
     */
    public static function removeAction(string $hook, ?callable $callback = null): void
    {
        if ($callback === null) {
            unset(self::$actions[$hook]);
        } elseif (isset(self::$actions[$hook])) {
            self::$actions[$hook] = array_filter(
                self::$actions[$hook],
                fn($action) => self::getCallbackHash($action['callback']) !== self::getCallbackHash($callback)
            );
        }
    }

    /**
     * 移除过滤器钩子
     */
    public static function removeFilter(string $hook, ?callable $callback = null): void
    {
        if ($callback === null) {
            unset(self::$filters[$hook]);
        } elseif (isset(self::$filters[$hook])) {
            self::$filters[$hook] = array_filter(
                self::$filters[$hook],
                fn($filter) => self::getCallbackHash($filter['callback']) !== self::getCallbackHash($callback)
            );
        }
    }

    /**
     * 检查是否存在动作钩子
     */
    public static function hasAction(string $hook): bool
    {
        return isset(self::$actions[$hook]) && !empty(self::$actions[$hook]);
    }

    /**
     * 检查是否存在过滤器钩子
     */
    public static function hasFilter(string $hook): bool
    {
        return isset(self::$filters[$hook]) && !empty(self::$filters[$hook]);
    }

    /**
     * 设置配置数据
     */
    public static function setConfig(array $config): void
    {
        self::$config = $config;
    }

    /**
     * 获取配置数据
     */
    public static function getConfig(): array
    {
        return self::$config;
    }

    /**
     * 获取动作配置
     */
    public static function getActions(): array
    {
        return self::$config['actions'] ?? [];
    }

    /**
     * 获取过滤器配置
     */
    public static function getFilters(): array
    {
        return self::$config['filters'] ?? [];
    }

    /**
     * 从配置加载钩子
     */
    public static function loadFromConfig(array $config): void
    {
        // 防止重复加载配置
        if (self::$initialized) {
            return;
        }

        self::$initialized = true;

        self::setConfig($config);
        self::registerActions(self::getActions());
        self::registerFilters(self::getFilters());
    }

    /**
     * 批量注册动作钩子
     */
    private static function registerActions(array $actions): void
    {
        foreach ($actions as $hook => $handlers) {
            if (is_callable($handlers)) {
                // 单个处理函数
                self::addAction($hook, $handlers);
            } elseif (is_array($handlers)) {
                if (isset($handlers[0]) && is_string($handlers[0])) {
                    // [class, method] 或 [class, method, priority] 格式
                    self::registerSingleAction($hook, $handlers);
                } else {
                    // 多个处理函数
                    foreach ($handlers as $handler) {
                        if (is_callable($handler)) {
                            self::addAction($hook, $handler);
                        } elseif (is_array($handler) && isset($handler[0])) {
                            self::registerSingleAction($hook, $handler);
                        }
                    }
                }
            }
        }
    }

    /**
     * 注册单个动作钩子
     */
    private static function registerSingleAction(string $hook, array $handler): void
    {
        $priority = 0;
        if (isset($handler[2])) {
            $priority = (int) $handler[2];
        } elseif (isset($handler[1]) && is_int($handler[1])) {
            $priority = (int) $handler[1];
        }

        if (isset($handler[0]) && is_string($handler[0]) && class_exists($handler[0])) {
            $instance = new $handler[0]();
            $method = $handler[1];
            if (is_string($method) && method_exists($instance, $method)) {
                self::addAction($hook, [$instance, $method], $priority);
            }
        } elseif (is_callable($handler)) {
            self::addAction($hook, $handler, $priority);
        }
    }

    /**
     * 批量注册过滤器钩子
     */
    private static function registerFilters(array $filters): void
    {
        foreach ($filters as $hook => $handlers) {
            if (is_callable($handlers)) {
                // 单个处理函数
                self::addFilter($hook, $handlers);
            } elseif (is_array($handlers)) {
                if (isset($handlers[0]) && is_string($handlers[0])) {
                    // [class, method] 或 [class, method, priority] 格式
                    self::registerSingleFilter($hook, $handlers);
                } else {
                    // 多个处理函数
                    foreach ($handlers as $handler) {
                        if (is_callable($handler)) {
                            self::addFilter($hook, $handler);
                        } elseif (is_array($handler) && isset($handler[0])) {
                            self::registerSingleFilter($hook, $handler);
                        }
                    }
                }
            }
        }
    }

    /**
     * 注册单个过滤器钩子
     */
    private static function registerSingleFilter(string $hook, array $handler): void
    {
        $priority = 0;
        if (isset($handler[2])) {
            $priority = (int) $handler[2];
        } elseif (isset($handler[1]) && is_int($handler[1])) {
            $priority = (int) $handler[1];
        }

        if (isset($handler[0]) && is_string($handler[0]) && class_exists($handler[0])) {
            $instance = new $handler[0]();
            $method = $handler[1];
            if (is_string($method) && method_exists($instance, $method)) {
                self::addFilter($hook, [$instance, $method], $priority);
            }
        } elseif (is_callable($handler)) {
            self::addFilter($hook, $handler, $priority);
        }
    }

    /**
     * 重置初始化状态（主要用于测试）
     */
    public static function reset(): void
    {
        self::$actions = [];
        self::$filters = [];
        self::$config = [];
        self::$initialized = false;
    }
}
