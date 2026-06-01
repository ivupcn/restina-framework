<?php
// src/queue/QueueInterface.php

namespace Restina\queue;

/**
 * 队列接口
 */
interface QueueInterface
{
    /**
     * 推送消息到队列
     */
    public function push(Job $job, string $queue = 'default', int $delay = 0): string;

    /**
     * 推送延迟消息到队列
     */
    public function later(int $delay, Job $job, string $queue = 'default'): string;

    /**
     * 从队列中取出消息
     */
    public function pop(string $queue = 'default'): ?Job;

    /**
     * 获取队列长度
     */
    public function size(string $queue = 'default'): int;

    /**
     * 删除队列中的消息
     */
    public function deleteMessage(string $messageId, string $queue = 'default'): bool;

    /**
     * 重新推送失败的消息
     */
    public function retryFailed(string $messageId): bool;

    /**
     * 获取失败的消息列表
     */
    public function getFailedJobs(): array;

    /**
     * 清除失败的消息
     */
    public function flushFailed(): bool;

    /**
     * 转换为数组
     */
    public function toArray(): array;

    /**
     * 获取尝试次数
     */
    public function getAttempts(): int;

    /**
     * 设置尝试次数
     */
    public function setAttempts(int $attempts): void;
}
