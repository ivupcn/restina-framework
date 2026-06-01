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
     * 不同级别的日志缓冲区
     * @var array
     */
    private array $logBuffers = [
        'error' => [],      // 错误级别日志
        'info' => [],       // 信息级别日志
        'default' => []     // 其他级别日志
    ];

    /**
     * 缓冲区最大条目数
     * @var int
     */
    private int $bufferLimit;

    /**
     * Logger constructor.
     * @param string $logPath 日志路径
     * @param int $bufferLimit 缓冲区最大条目数，默认1000
     */
    public function __construct(string $logPath, int $bufferLimit = 1000)
    {
        $this->logPath = $logPath;
        $this->bufferLimit = $bufferLimit;
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

        // 根据日志级别分配到不同的缓冲区
        $channel = $this->getChannelByLevel($level);
        $this->logBuffers[$channel][] = $logEntry;

        // 检查是否达到缓冲区限制
        if (count($this->logBuffers[$channel]) >= $this->bufferLimit) {
            $this->writeChannel($channel);
        }

        // 在 FrankenPHP 环境中，对于错误级别的日志立即写入
        if (RUN_MODE === 'worker' && in_array($level, ['error', 'critical', 'alert', 'emergency'])) {
            $this->writeChannel('error');
        }
    }

    /**
     * 根据日志级别获取对应的通道
     * @param string $level 日志级别
     * @return string
     */
    private function getChannelByLevel(string $level): string
    {
        if (in_array($level, ['error', 'critical', 'alert', 'emergency'])) {
            return 'error';
        } elseif (in_array($level, ['info', 'notice', 'debug'])) {
            return 'info';
        }
        return 'default';
    }

    /**
     * 写入指定通道的日志
     * @param string $channel 通道名称
     */
    private function writeChannel(string $channel): void
    {
        if (empty($this->logBuffers[$channel])) {
            return; // 如果没有缓存的日志，直接返回
        }

        $logEntries = [];
        foreach ($this->logBuffers[$channel] as $entry) {
            $logMessage = "[{$entry['timestamp']}] {$entry['level']}: {$entry['message']}" . PHP_EOL;

            if (!empty($entry['context'])) {
                $logMessage .= "Context: " . json_encode($entry['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . PHP_EOL;
            }

            $logEntries[] = $logMessage;
        }

        // 组合所有日志消息
        $combinedLog = implode('', $logEntries);

        // 写入对应级别的日志文件
        $logFile = $this->getLogFilePath($channel);
        file_put_contents($logFile, $combinedLog, FILE_APPEND | LOCK_EX);

        // 清空对应通道的缓冲区
        $this->logBuffers[$channel] = [];
    }

    /**
     * 将所有缓冲区的日志一次性写入文件
     */
    public function write(): void
    {
        foreach (array_keys($this->logBuffers) as $channel) {
            $this->writeChannel($channel);
        }
    }

    /**
     * 获取日志文件的路径
     *
     * @param string $channel 通道名称
     * @return string
     */
    private function getLogFilePath(string $channel): string
    {
        if (!is_dir($this->logPath)) {
            mkdir($this->logPath, 0755, true);
        }

        $date = date('Y-m-d');

        // 根据通道返回不同的文件名
        switch ($channel) {
            case 'error':
                return $this->logPath . DIRECTORY_SEPARATOR . "{$date}_error.log";
            case 'info':
                return $this->logPath . DIRECTORY_SEPARATOR . "{$date}_info.log";
            default:
                return $this->logPath . DIRECTORY_SEPARATOR . "{$date}.log";
        }
    }

    /**
     * 立即写入所有日志（用于 FrankenPHP Worker 模式）
     */
    public function flush(): void
    {
        $this->write();
    }

    /**
     * 获取缓冲区统计信息
     * @return array
     */
    public function getBufferStats(): array
    {
        $stats = [];
        foreach ($this->logBuffers as $channel => $buffer) {
            $stats[$channel] = count($buffer);
        }
        return $stats;
    }
}
