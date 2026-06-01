<?php
// src/queue/driver/DatabaseQueue.php

namespace Restina\queue\driver;

use Restina\Db;

class Database implements QueueInterface
{
    private Db $db;
    private string $table;
    private string $failedTable;

    public function __construct(Db $db, string $table = 'queue_jobs', string $failedTable = 'queue_failed_jobs')
    {
        $this->db = $db;
        $this->table = $table;
        $this->failedTable = $failedTable;

        $this->createTablesIfNotExists();
    }

    public function push(Job $job, string $queue = 'default', int $delay = 0): string
    {
        $message = new Message($job, $queue);

        if ($delay > 0) {
            $availableAt = date('Y-m-d H:i:s', time() + $delay);
        } else {
            $availableAt = date('Y-m-d H:i:s');
        }

        $this->db->table($this->table)->insert([
            'id' => $message->id,
            'queue' => $queue,
            'payload' => $message->serialize(),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $availableAt,
            'created_at' => date('Y-m-d H:i:s'),
            'max_attempts' => $message->maxAttempts
        ]);

        return $message->id;
    }

    public function later(int $delay, Job $job, string $queue = 'default'): string
    {
        return $this->push($job, $queue, $delay);
    }

    public function pop(string $queue = 'default'): ?Job
    {
        $now = date('Y-m-d H:i:s');

        // 查找可执行的任务（未预留且已到达执行时间）
        $job = $this->db
            ->table($this->table)
            ->where('queue', $queue)
            ->whereNull('reserved_at')
            ->where('available_at', '<=', $now)
            ->orderBy('available_at', 'asc')
            ->first();

        if ($job) {
            // 预留任务（防止其他进程同时消费）
            $reservedAt = date('Y-m-d H:i:s');
            $this->db
                ->table($this->table)
                ->where('id', $job->id)
                ->update(['reserved_at' => $reservedAt]);

            $message = Message::unserialize($job->payload);
            $message->attempts = $job->attempts;

            return $message;
        }

        return null;
    }

    public function size(string $queue = 'default'): int
    {
        $now = date('Y-m-d H:i:s');

        return $this->db
            ->table($this->table)
            ->where('queue', $queue)
            ->where('available_at', '<=', $now)
            ->count();
    }

    public function deleteMessage(string $messageId, string $queue = 'default'): bool
    {
        return $this->db
            ->table($this->table)
            ->where('id', $messageId)
            ->where('queue', $queue)
            ->delete() > 0;
    }

    public function retryFailed(string $messageId): bool
    {
        $failedJob = $this->db
            ->table($this->failedTable)
            ->where('id', $messageId)
            ->first();

        if (!$failedJob) {
            return false;
        }

        // 解析原始消息
        $message = Message::unserialize($failedJob->payload);
        $message->attempts = 0; // 重置尝试次数

        // 重新推送到队列
        $this->push($message->job, $message->queue);

        // 从失败表删除
        $this->db
            ->table($this->failedTable)
            ->where('id', $messageId)
            ->delete();

        return true;
    }

    public function getFailedJobs(): array
    {
        $failedJobs = $this->db
            ->table($this->failedTable)
            ->orderBy('failed_at', 'desc')
            ->get();

        return array_map(function ($job) {
            return Message::unserialize($job->payload);
        }, $failedJobs->toArray());
    }

    public function flushFailed(): bool
    {
        return $this->db
            ->table($this->failedTable)
            ->delete() > 0;
    }

    /**
     * 创建队列表
     */
    private function createTablesIfNotExists(): void
    {
        // 创建队列表
        $this->db->statement("
            CREATE TABLE IF NOT EXISTS `{$this->table}` (
                `id` VARCHAR(255) PRIMARY KEY,
                `queue` VARCHAR(255) NOT NULL,
                `payload` LONGTEXT NOT NULL,
                `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
                `reserved_at` TIMESTAMP NULL,
                `available_at` TIMESTAMP NOT NULL,
                `created_at` TIMESTAMP NOT NULL,
                `max_attempts` INT UNSIGNED NOT NULL DEFAULT 3
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 创建失败队列表
        $this->db->statement("
            CREATE TABLE IF NOT EXISTS `{$this->failedTable}` (
                `id` VARCHAR(255) PRIMARY KEY,
                `connection` VARCHAR(255) NOT NULL,
                `queue` VARCHAR(255) NOT NULL,
                `payload` LONGTEXT NOT NULL,
                `exception` LONGTEXT,
                `failed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * 标记消息为已完成
     */
    public function markCompleted(Message $message, string $queue = 'default'): void
    {
        $this->db
            ->table($this->table)
            ->where('id', $message->id)
            ->delete();
    }

    /**
     * 标记消息为失败
     */
    public function markFailed(Message $message, string $queue = 'default'): void
    {
        $this->db
            ->table($this->table)
            ->where('id', $message->id)
            ->delete();

        $this->db
            ->table($this->failedTable)
            ->insert([
                'id' => $message->id,
                'connection' => 'database',
                'queue' => $queue,
                'payload' => $message->serialize(),
                'exception' => 'Job execution failed',
                'failed_at' => date('Y-m-d H:i:s')
            ]);
    }
}
