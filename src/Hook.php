<?php
// restina/Hook.php

namespace Restina;

use InvalidArgumentException;

/**
 * 钩子类
 */
class Hook
{
    private static array $actions = [];
    private static array $filters = [];
    private static array $pipes = [];
    private static array $config = [];
    private static bool $initialized = false;

    /**
     * 添加动作钩子
     */
    public static function addAction(string $hook, callable $callback, int $priority = 10): void
    {
        self::addHookItem('action', $hook, $callback, $priority, self::$actions);
    }

    /**
     * 添加过滤器钩子
     */
    public static function addFilter(string $hook, callable $callback, int $priority = 10): void
    {
        self::addHookItem('filter', $hook, $callback, $priority, self::$filters);
    }

    /**
     * 注册一个管道任务（支持 $next 的中间件）
     * @param string $hook 管道的名称/标识
     * @param callable $callback 回调函数，必须接收 $payload 和 $next 两个参数
     * @param int $priority 优先级
     * @return void
     */
    public static function addPipe(string $hook, callable $callback, int $priority = 10): void
    {
        self::addHookItem('pipe', $hook, $callback, $priority, self::$pipes);
    }

    /**
     * 添加钩子项的通用方法
     */
    private static function addHookItem(string $type, string $hook, callable $callback, int $priority, array &$storage): void
    {
        if (!isset($storage[$hook])) {
            $storage[$hook] = [];
        }

        // 防止重复注册相同的回调
        $callbackHash = self::getCallbackHash($callback);
        foreach ($storage[$hook] as $existing) {
            if (self::getCallbackHash($existing['callback']) === $callbackHash) {
                return; // 已存在，不再注册
            }
        }

        $storage[$hook][] = [
            'callback' => $callback,
            'priority' => $priority
        ];

        // 按优先级升序排序（高优先级先执行）
        usort($storage[$hook], function ($a, $b) {
            return $a['priority'] <=> $b['priority'];
        });
    }

    /**
     * 生成回调函数的哈希值
     */
    private static function getCallbackHash(callable $callback): string
    {
        if (is_array($callback)) {
            $class = is_object($callback[0])
                ? spl_object_hash($callback[0])
                : $callback[0];
            return $class . '::' . $callback[1];
        } elseif (is_string($callback)) {
            return $callback;
        } elseif (is_object($callback)) {
            return spl_object_hash($callback);
        }

        // 对于闭包等复杂情况，使用MD5序列化
        return md5(serialize($callback));
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
     * 执行管道任务（启动洋葱模型）
     * @param string $hook 管道的名称
     * @param mixed $payload 传递给中间件的数据（如 Request 对象）
     * @return mixed 返回最终的处理结果
     */
    public static function runPipe(string $hook, $payload)
    {
        // 如果没有注册该管道，直接返回 payload
        if (!isset(self::$pipes[$hook]) || empty(self::$pipes[$hook])) {
            return $payload;
        }

        $pipes = self::$pipes[$hook];
        // 使用 reduce 模式构建中间件链
        $stack = array_reduce(
            array_reverse($pipes),
            function ($next, $pipe) {
                return function ($payload) use ($pipe, $next) {
                    return $pipe['callback']($payload, $next);
                };
            },
            function ($payload) {
                return $payload;
            }  // 默认终点函数
        );

        return $stack($payload);
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
     * 移除管道钩子
     */
    public static function removePipe(string $hook, ?callable $callback = null): void
    {
        if ($callback === null) {
            unset(self::$pipes[$hook]);
        } elseif (isset(self::$pipes[$hook])) {
            self::$pipes[$hook] = array_filter(
                self::$pipes[$hook],
                fn($pipe) => self::getCallbackHash($pipe['callback']) !== self::getCallbackHash($callback)
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
     * 检查是否存在管道钩子
     */
    public static function hasPipe(string $hook): bool
    {
        return isset(self::$pipes[$hook]) && !empty(self::$pipes[$hook]);
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
        return is_array(self::$config['actions'] ?? [])
            ? self::$config['actions']
            : [];
    }

    /**
     * 获取过滤器配置
     */
    public static function getFilters(): array
    {
        return is_array(self::$config['filters'] ?? [])
            ? self::$config['filters']
            : [];
    }

    /**
     * 获取管道配置
     */
    public static function getPipes(): array
    {
        return is_array(self::$config['pipes'] ?? [])
            ? self::$config['pipes']
            : [];
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
        self::registerPipes(self::getPipes());
    }

    /**
     * 批量注册动作钩子
     */
    private static function registerActions(array $actions): void
    {
        foreach ($actions as $hook => $handlers) {
            self::registerMultipleHandlers($hook, $handlers, [self::class, 'addAction']);
        }
    }

    /**
     * 批量注册过滤器钩子
     */
    private static function registerFilters(array $filters): void
    {
        foreach ($filters as $hook => $handlers) {
            self::registerMultipleHandlers($hook, $handlers, [self::class, 'addFilter']);
        }
    }

    /**
     * 批量注册管道钩子
     */
    private static function registerPipes(array $pipes): void
    {
        foreach ($pipes as $hook => $handlers) {
            self::registerMultipleHandlers($hook, $handlers, [self::class, 'addPipe']);
        }
    }

    /**
     * 通用的处理器注册方法
     */
    private static function registerMultipleHandlers(string $hook, $handlers, callable $registrationFunction): void
    {
        if (is_callable($handlers)) {
            // 单个处理函数
            $registrationFunction($hook, $handlers);
        } elseif (is_array($handlers)) {
            if (isset($handlers[0]) && is_string($handlers[0])) {
                // [class, method] 或 [class, method, priority] 格式
                self::registerSingleHandler($hook, $handlers, $registrationFunction);
            } else {
                // 多个处理函数
                foreach ($handlers as $handler) {
                    if (is_callable($handler)) {
                        $registrationFunction($hook, $handler);
                    } elseif (is_array($handler) && isset($handler[0])) {
                        self::registerSingleHandler($hook, $handler, $registrationFunction);
                    }
                }
            }
        }
    }

    /**
     * 注册单个处理器
     */
    private static function registerSingleHandler(string $hook, array $handler, callable $registrationFunction): void
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
                $registrationFunction($hook, [$instance, $method], $priority);
            }
        } elseif (is_callable($handler)) {
            $registrationFunction($hook, $handler, $priority);
        }
    }

    /**
     * 重置初始化状态（主要用于测试）
     */
    public static function reset(): void
    {
        self::$actions = [];
        self::$filters = [];
        self::$pipes = [];
        self::$config = [];
        self::$initialized = false;
    }

    /**
     * 获取钩子信息（用于调试）
     */
    public static function getHookInfo(?string $type = null): array
    {
        switch ($type) {
            case 'action':
                return self::$actions;
            case 'filter':
                return self::$filters;
            case 'pipe':
                return self::$pipes;
            default:
                return [
                    'actions' => self::$actions,
                    'filters' => self::$filters,
                    'pipes' => self::$pipes
                ];
        }
    }
}
