<?php
// restina/Logger.php

namespace Restina;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * 日志类
 * @package Restina
 */
class Logger extends AbstractLogger implements LoggerInterface
{
    /**
     * 日志路径
     * @var string
     */
    private string $logPath;

    /**
     * 缓存的日志条目
     * @var array
     */
    private array $logBuffer = [];

    /**
     * 是否在 FrankenPHP 环境中
     * @var bool
     */
    private bool $isFrankenphp = false;

    /**
     * Logger constructor.
     * @param string $logPath 日志路径
     */
    public function __construct(string $logPath)
    {
        $this->logPath = $logPath;
        $this->isFrankenphp = function_exists('frankenphp_handle_request');
    }

    /**
     * 记录日志
     * @param mixed $level 日志级别
     * @param string $message 日志内容
     * @param array $context 日志上下文
     */
    public function log($level, $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = [
            'timestamp' => $timestamp,
            'level' => $level,
            'message' => $message,
            'context' => $context
        ];

        $this->logBuffer[] = $logEntry;

        // 在 FrankenPHP 环境中，对于错误级别的日志立即写入
        if ($this->isFrankenphp && in_array($level, ['error', 'critical', 'alert', 'emergency'])) {
            $this->write();
        }
    }

    /**
     * 将缓存的日志一次性写入文件
     */
    public function write(): void
    {
        if (empty($this->logBuffer)) {
            return; // 如果没有缓存的日志，直接返回
        }

        $logEntries = [];
        foreach ($this->logBuffer as $entry) {
            $logMessage = "[{$entry['timestamp']}] {$entry['level']}: {$entry['message']}" . PHP_EOL;

            if (!empty($entry['context'])) {
                $logMessage .= "Context: " . json_encode($entry['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . PHP_EOL;
            }

            $logEntries[] = $logMessage;
        }

        // 组合所有日志消息
        $combinedLog = implode('', $logEntries);

        // 写入日志文件
        $logFile = $this->getLogFilePath();
        file_put_contents($logFile, $combinedLog, FILE_APPEND | LOCK_EX);

        // 清空缓冲区
        $this->logBuffer = [];
    }

    /**
     * 获取日志文件的路径
     *
     * @return string
     */
    private function getLogFilePath(): string
    {
        if (!is_dir($this->logPath)) {
            mkdir($this->logPath, 0755, true);
        }

        return $this->logPath . DIRECTORY_SEPARATOR . date('Y-m-d') . '.log';
    }

    /**
     * 立即写入日志（用于 FrankenPHP Worker 模式）
     */
    public function flush(): void
    {
        $this->write();
    }
}
