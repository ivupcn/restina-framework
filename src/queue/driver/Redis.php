<?php
// src/queue/driver/RedisQueue.php

namespace Restina\queue\driver;

use Restina\Redis as RedisClient;
use Restina\Config;

/**
 * Redis队列实现
 */
class Redis implements QueueInterface
{
    private RedisClient $redis;
    private string $prefix;
    private string $failedKey;

    public function __construct(Config $config)
    {
        $this->redis = new RedisClient($config);
        $this->prefix = $config->get('queue.redis.prefix', 'restina:queue:');
        $this->failedKey = $config->get('queue.redis.failed_key', 'restina:queue:failed');
    }

    public function push(Job $job, string $queue = 'default', int $delay = 0): string
    {
        $message = new Message($job, $queue);

        if ($delay > 0) {
            $message->availableAt = time() + $delay;
            $queueKey = $this->getDelayedQueueKey($queue);
            $this->redis->zadd($queueKey, $message->availableAt, $message->serialize());
        } else {
            $queueKey = $this->getQueueKey($queue);
            $this->redis->lpush($queueKey, $message->serialize());
        }

        return $message->id;
    }

    public function later(int $delay, Job $job, string $queue = 'default'): string
    {
        return $this->push($job, $queue, $delay);
    }

    public function pop(string $queue = 'default'): ?Job
    {
        // 先处理延迟队列
        $this->moveDelayedToReady($queue);

        $queueKey = $this->getQueueKey($queue);
        $serializedMessage = $this->redis->brpop([$queueKey], 1);

        if ($serializedMessage) {
            $messageData = $serializedMessage[1];
            $message = Message::unserialize($messageData);

            // 移动到正在处理队列
            $processingKey = $this->getProcessingQueueKey($queue);
            $this->redis->lpush($processingKey, $messageData);

            return $message;
        }

        return null;
    }

    public function size(string $queue = 'default'): int
    {
        $readyQueueKey = $this->getQueueKey($queue);
        $processingQueueKey = $this->getProcessingQueueKey($queue);

        $readySize = $this->redis->llen($readyQueueKey);
        $processingSize = $this->redis->llen($processingQueueKey);

        return $readySize + $processingSize;
    }

    public function deleteMessage(string $messageId, string $queue = 'default'): bool
    {
        $processingKey = $this->getProcessingQueueKey($queue);

        // 获取处理中的消息列表
        $messages = $this->redis->lrange($processingKey, 0, -1);

        foreach ($messages as $index => $serializedMessage) {
            $message = Message::unserialize($serializedMessage);
            if ($message->id === $messageId) {
                // 从处理队列中移除
                $this->redis->lrem($processingKey, 1, $serializedMessage);
                return true;
            }
        }

        return false;
    }

    public function retryFailed(string $messageId): bool
    {
        $failedMessages = $this->getFailedJobs();

        foreach ($failedMessages as $index => $serializedMessage) {
            $message = Message::unserialize($serializedMessage);
            if ($message->id === $messageId) {
                // 从失败队列移除
                $this->redis->lrem($this->failedKey, 1, $serializedMessage);

                // 重新推送到队列
                $this->push($message->job, $message->queue);

                return true;
            }
        }

        return false;
    }

    public function getFailedJobs(): array
    {
        $failedMessages = $this->redis->lrange($this->failedKey, 0, -1);
        return array_map(function ($serialized) {
            return Message::unserialize($serialized);
        }, $failedMessages);
    }

    public function flushFailed(): bool
    {
        return $this->redis->del($this->failedKey) > 0;
    }

    /**
     * 将延迟消息移到就绪队列
     */
    private function moveDelayedToReady(string $queue): void
    {
        $delayedKey = $this->getDelayedQueueKey($queue);
        $now = time();

        // 获取所有到期的延迟消息
        $expiredMessages = $this->redis->zrangebyscore($delayedKey, '-inf', $now);

        foreach ($expiredMessages as $serializedMessage) {
            // 从延迟队列中移除
            $this->redis->zrem($delayedKey, $serializedMessage);

            // 添加到就绪队列
            $queueKey = $this->getQueueKey($queue);
            $this->redis->lpush($queueKey, $serializedMessage);
        }
    }

    private function getQueueKey(string $queue): string
    {
        return $this->prefix . 'queues:' . $queue;
    }

    private function getDelayedQueueKey(string $queue): string
    {
        return $this->prefix . 'delayed:' . $queue;
    }

    private function getProcessingQueueKey(string $queue): string
    {
        return $this->prefix . 'processing:' . $queue;
    }

    /**
     * 标记消息为已完成
     */
    public function markCompleted(Message $message, string $queue = 'default'): void
    {
        $processingKey = $this->getProcessingQueueKey($queue);
        $this->redis->lrem($processingKey, 1, $message->serialize());
    }

    /**
     * 标记消息为失败
     */
    public function markFailed(Message $message, string $queue = 'default'): void
    {
        $processingKey = $this->getProcessingQueueKey($queue);
        $this->redis->lrem($processingKey, 1, $message->serialize());

        // 添加到失败队列
        $this->redis->lpush($this->failedKey, $message->serialize());
    }
}
