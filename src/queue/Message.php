<?php
// src/queue/Message.php

namespace Restina\queue;

/**
 * 消息类
 */
class Message
{
    /**
     * 消息ID
     */
    public string $id;

    /**
     * 队列名称
     */
    public string $queue;

    /**
     * 任务对象
     */
    public Job $job;

    /**
     * 创建时间
     */
    public int $createdAt;

    /**
     * 执行次数
     */
    public int $attempts = 0;

    /**
     * 最大尝试次数
     */
    public int $maxAttempts = 3;

    /**
     * 延迟执行时间戳
     */
    public ?int $availableAt = null;

    /**
     * 构造函数
     */
    public function __construct(Job $job, string $queue = 'default')
    {
        $this->id = uniqid('msg_', true);
        $this->job = $job;
        $this->queue = $queue;
        $this->createdAt = time();
    }

    /**
     * 检查是否可以执行
     */
    public function canRun(): bool
    {
        if ($this->availableAt && time() < $this->availableAt) {
            return false;
        }

        return $this->attempts < $this->maxAttempts;
    }

    /**
     * 标记为已尝试一次
     */
    public function markAsAttempted(): void
    {
        $this->attempts++;
    }

    /**
     * 检查是否已达到最大尝试次数
     */
    public function hasExceededMaxAttempts(): bool
    {
        return $this->attempts >= $this->maxAttempts;
    }

    /**
     * 序列化消息
     */
    public function serialize(): string
    {
        return serialize([
            'id' => $this->id,
            'queue' => $this->queue,
            'job' => $this->job,
            'createdAt' => $this->createdAt,
            'attempts' => $this->attempts,
            'maxAttempts' => $this->maxAttempts,
            'availableAt' => $this->availableAt
        ]);
    }

    /**
     * 反序列化消息
     */
    public static function unserialize(string $data): self
    {
        $properties = unserialize($data);

        $message = new self($properties['job'], $properties['queue']);
        $message->id = $properties['id'];
        $message->createdAt = $properties['createdAt'];
        $message->attempts = $properties['attempts'];
        $message->maxAttempts = $properties['maxAttempts'];
        $message->availableAt = $properties['availableAt'];

        return $message;
    }
}
