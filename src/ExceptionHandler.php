<?php
// restina/ExceptionHandler.php

namespace Restina;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Restina\Response;
use Throwable;

class ExceptionHandler
{
    private LoggerInterface $logger;
    private bool $debug;

    public function __construct(LoggerInterface $logger, bool $debug = false)
    {
        $this->logger = $logger;
        $this->debug = $debug;
    }

    /**
     * Handle an exception and return appropriate response
     */
    public function handle(
        ServerRequestInterface $request,
        Throwable $exception
    ): Response {
        // Log the exception
        $this->logException($exception);

        // Create response based on exception type
        $response = $this->createResponse($exception);

        return $response;
    }

    /**
     * Log exception details
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

        $this->logger->error('Unhandled exception occurred', $context);
    }

    /**
     * Create appropriate response based on exception
     */
    private function createResponse(Throwable $exception): Response
    {
        // Set status code based on exception type
        $statusCode = $this->getStatusCode($exception);

        // Create response body
        $body = $this->createResponseBody($exception, $statusCode);

        // Use your framework's Response class to create JSON response
        return Response::error(
            message: $exception->getMessage(),
            status: $statusCode,
            details: $this->getErrorDetails($exception)
        );
    }

    /**
     * Determine status code from exception
     */
    private function getStatusCode(Throwable $exception): int
    {
        // If it's a custom framework exception with specific codes
        if (method_exists($exception, 'getStatusCode')) {
            return $exception->getStatusCode();
        }

        // Map common exceptions to appropriate status codes
        switch (true) {
            case $exception instanceof \InvalidArgumentException:
                return 400; // Bad Request
            case $exception instanceof \OutOfBoundsException:
                return 404; // Not Found
            case $exception instanceof \DomainException:
                return 403; // Forbidden
            case $exception instanceof \RuntimeException:
                return 501; // Not Implemented
            default:
                return 500; // Internal Server Error
        }
    }

    /**
     * Create response body based on exception details
     */
    private function createResponseBody(Throwable $exception, int $statusCode): array
    {
        $body = [
            'success' => false,
            'error' => [
                'type' => get_class($exception),
                'message' => $exception->getMessage(),
                'code' => $statusCode,
            ]
        ];

        // Include additional debug information in development mode
        if ($this->debug) {
            $body['error']['details'] = [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => explode("\n", $exception->getTraceAsString()),
            ];
        }

        return $body;
    }

    /**
     * Get error details based on debug mode
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
