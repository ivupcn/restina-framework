<?php
// restina/Queue.php

namespace Restina;

use Restina\Config;
use Restina\Db;
use Restina\queue\driver\Database as DatabaseQueue;
use Restina\queue\driver\Redis as RedisQueue;

class Queue
{
    private QueueInterface $driver;
    private string $defaultConnection;
    private Config $config;
    private Db $db;

    public function __construct(Config $config, Db $db)
    {
        $this->config = $config;
        $this->db = $db;
        $this->defaultConnection = $config->get('queue.default', 'redis');

        $this->driver = $this->createDriver($this->defaultConnection);
    }

    /**
     * 创建驱动实例
     */
    private function createDriver(string $connection): QueueInterface
    {
        $connections = $this->config->get('queue.connections', []);
        $config = $connections[$connection] ?? [];

        switch ($config['driver'] ?? $connection) {
            case 'redis':
                return new RedisQueue($this->config);
            case 'database':
                return new DatabaseQueue($this->db, $config['table'] ?? 'queue_jobs', $config['failed_table'] ?? 'queue_failed_jobs');
            default:
                throw new \InvalidArgumentException("Unsupported queue driver: {$connection}");
        }
    }

    /**
     * 获取队列驱动
     */
    public function getDriver(): QueueInterface
    {
        return $this->driver;
    }

    /**
     * 序列化任务对象
     */
    private function serializeJob(Job $job): string
    {
        try {
            $jobData = [
                'class' => get_class($job),
                'data' => $job->toArray(),
                'attempts' => $job->getAttempts() ?? 0,
                'createdAt' => time()
            ];

            $serialized = json_encode($jobData);
            if ($serialized === false) {
                throw new \RuntimeException('Job serialization failed: ' . json_last_error_msg());
            }

            return $serialized;
        } catch (\Exception $e) {
            throw new \RuntimeException('Job serialization error: ' . $e->getMessage());
        }
    }

    /**
     * 反序列化任务对象
     */
    private function unserializeJob(string $serializedData): ?Job
    {
        $jobData = json_decode($jobData, true);
        if (!$jobData || !isset($jobData['class'])) {
            return null;
        }

        $className = $jobData['class'];
        if (!class_exists($className)) {
            return null;
        }

        $job = new $className($jobData['data']);
        if (method_exists($job, 'setAttempts')) {
            $job->setAttempts($jobData['attempts'] ?? 0);
        }

        return $job;
    }

    /**
     * 推送任务到队列
     */
    public function push(Job $job, string $queue = 'default', int $delay = 0): string
    {
        $serializedJob = $this->serializeJob($job);
        return $this->driver->push($serializedJob, $queue, $delay);
    }

    /**
     * 推送延迟任务
     */
    public function later(int $delay, Job $job, string $queue = 'default'): string
    {
        $serializedJob = $this->serializeJob($job);
        return $this->driver->later($delay, $serializedJob, $queue);
    }

    /**
     * 从队列获取任务
     */
    public function pop(string $queue = 'default'): ?Job
    {
        $serializedJob = $this->driver->pop($queue);
        if ($serializedJob === null) {
            return null;
        }

        return $this->unserializeJob($serializedJob);
    }

    /**
     * 获取队列大小
     */
    public function size(string $queue = 'default'): int
    {
        return $this->driver->size($queue);
    }

    /**
     * 重试失败的任务（支持延迟重试）
     */
    public function retryFailed(string $messageId, int $delay = 0): bool
    {
        $failedJobs = $this->driver->getFailedJobs();
        $jobToRetry = null;

        foreach ($failedJobs as $job) {
            if ($job['id'] === $messageId) {
                $jobToRetry = $job;
                break;
            }
        }

        if (!$jobToRetry) {
            return false;
        }

        // 反序列化失败的任务
        $job = $this->unserializeJob($jobToRetry['payload']);
        if (!$job) {
            return false;
        }

        if ($delay > 0) {
            // 延迟重试
            return $this->driver->later($delay, $this->serializeJob($job), $jobToRetry['queue'] ?? 'default');
        } else {
            // 立即重试
            return $this->driver->push($this->serializeJob($job), $jobToRetry['queue'] ?? 'default');
        }
    }

    /**
     * 获取失败的任务
     */
    public function getFailedJobs(): array
    {
        return $this->driver->getFailedJobs();
    }

    /**
     * 清除失败的任务
     */
    public function flushFailed(): bool
    {
        return $this->driver->flushFailed();
    }

    /**
     * 获取驱动类型
     */
    public function getDriverType(): string
    {
        return $this->defaultConnection;
    }
}
