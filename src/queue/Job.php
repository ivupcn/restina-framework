<?php
// src/queue/Job.php

namespace Restina\queue;

use Closure;

class Job
{
    /**
     * 任务回调或类名
     */
    public string|Closure $callback;

    /**
     * 任务数据
     */
    public array $data;

    /**
     * 任务处理器
     */
    public ?string $handlerClass = null;

    /**
     * 任务处理器方法
     */
    public string $handlerMethod = 'handle';

    /**
     * 构造函数
     */
    public function __construct(string|Closure $callback, array $data = [])
    {
        if (is_string($callback) && class_exists($callback)) {
            $this->handlerClass = $callback;
        } else {
            $this->callback = $callback;
        }

        $this->data = $data;
    }

    /**
     * 执行任务
     */
    public function handle(): bool
    {
        try {
            if ($this->handlerClass) {
                $handler = new $this->handlerClass();
                return $handler->{$this->handlerMethod}($this->data);
            } elseif ($this->callback instanceof Closure) {
                return call_user_func($this->callback, $this->data);
            }

            return false;
        } catch (\Throwable $e) {
            error_log("Queue job failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取任务标识
     */
    public function getJobId(): string
    {
        if ($this->handlerClass) {
            return $this->handlerClass;
        } elseif ($this->callback instanceof Closure) {
            return 'closure_' . spl_object_hash($this->callback);
        }

        return 'unknown';
    }
}
