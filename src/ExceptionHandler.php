<?php
// restina/ExceptionHandler.php

namespace Restina;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Restina\Response;
use Throwable;

/**
 * Handles exceptions
 * @package Restina
 */
class ExceptionHandler
{
    // 注入日志接口
    private LoggerInterface $logger;
    // 运行时环境
    private bool $debug;

    /**
     * 构造函数
     *
     * @param LoggerInterface $logger 日志接口
     * @param bool $debug 是否为调试模式
     */
    public function __construct(LoggerInterface $logger, bool $debug = false)
    {
        $this->logger = $logger;
        $this->debug = $debug;
    }


    /**
     * 异常处理
     *
     * @param ServerRequestInterface $request 请求对象
     * @param Throwable $exception 异常对象
     * @return ResponseInterface 响应对象
     */
    public function handle(ServerRequestInterface $request, Throwable $exception): Response
    {
        $this->logException($exception);
        return Response::error(
            message: $exception->getMessage(),
            status: $exception->getCode(),
            details: $this->getErrorDetails($exception)
        );
    }

    /**
     * 记录异常
     *
     * @param Throwable $exception 异常对象
     */
    private function logException(Throwable $exception): void
    {
        $context = [
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ];

        $this->logger->log('error', '发生异常错误', $context);
    }

    /**
     * 获取错误详情
     *
     * @param Throwable $exception 异常对象
     * @return array 响应体数据
     */
    private function getErrorDetails(Throwable $exception): array
    {
        if ($this->debug) {
            return [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => explode("\n", $exception->getTraceAsString()),
            ];
        }
        return [];
    }
}
