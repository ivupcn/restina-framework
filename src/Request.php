<?php
// restina/Request.php

namespace Restina;

use Psr\Http\Message\ServerRequestInterface;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;
use Nyholm\Psr7\UploadedFile;
use Nyholm\Psr7\Stream;

/**
 * 请求类
 * @package Restina
 */
class Request
{
    private const DEFAULT_HTTPS_PORT = 443;
    private const DEFAULT_HTTP_PORT = 80;
    private const MAX_PORT_NUMBER = 65535;
    private const MIN_PORT_NUMBER = 1;

    /**
     * 从全局变量创建请求对象
     */
    public static function createFromGlobals(): ServerRequestInterface
    {
        if (RUN_MODE === 'worker') {
            // 在 FrankenPHP 环境下，使用特殊处理
            return self::createFromWorker();
        }
        // 预先读取输入流，供多处使用
        $inputContent = file_get_contents('php://input') ?: '';

        // 获取请求方法
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // 获取 URI
        $uri = self::createUri();

        // 创建请求对象
        $request = new ServerRequest($method, $uri);

        // 添加请求头
        foreach (self::getRequestHeaders() as $name => $value) {
            $request = $request->withAddedHeader($name, $value);
        }

        // 添加请求体
        $request = $request->withBody(self::createBody($inputContent));

        // 添加查询参数
        $request = $request->withQueryParams($_GET ?? []);

        // 添加 Cookie
        $request = $request->withCookieParams($_COOKIE ?? []);

        // 添加上传文件
        $request = $request->withUploadedFiles(self::createUploadedFiles($_FILES ?? []));

        // 添加请求属性
        $request = $request->withParsedBody(self::parseRequestBody($inputContent));

        return $request;
    }

    /**
     * 从 FrankenPHP 环境创建请求对象
     */
    private static function createFromWorker(): ServerRequestInterface
    {
        // 在 FrankenPHP 环境中，全局变量依然可用
        $inputContent = file_get_contents('php://input') ?: '';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = self::createUri();

        $request = new ServerRequest($method, $uri);

        // 添加请求头
        foreach (self::getRequestHeaders() as $name => $value) {
            $request = $request->withAddedHeader($name, $value);
        }

        // 添加请求体
        $request = $request->withBody(self::createBody($inputContent));

        // 添加查询参数
        $request = $request->withQueryParams($_GET ?? []);

        // 添加 Cookie
        $request = $request->withCookieParams($_COOKIE ?? []);

        // 添加上传文件
        $request = $request->withUploadedFiles(self::createUploadedFiles($_FILES ?? []));

        // 添加请求属性
        $request = $request->withParsedBody(self::parseRequestBody($inputContent));

        return $request;
    }

    /**
     * 创建 URI 对象
     */
    private static function createUri(): Uri
    {
        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';

        // 使用PHP 8的str_contains替代strpos
        $rawHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $host = self::sanitizeHost($rawHost);

        $port = self::getValidatedPort($scheme);

        $path = $_SERVER['REQUEST_URI'] ?? '/';
        // 使用PHP 8的str_starts_with替代explode
        $path = match (true) {
            str_contains($path, '?') => strstr($path, '?', true),
            default => $path
        };

        // 规范化路径
        $path = self::normalizePath($path);

        // 验证路径安全性 - 使用PHP 8的match表达式
        if (str_contains($path, '../')) {
            throw new \InvalidArgumentException('Invalid path contains directory traversal');
        }

        $queryString = $_SERVER['QUERY_STRING'] ?? '';

        $uriString = match (true) {
            $port !== null => "{$scheme}://{$host}:{$port}{$path}",
            default => "{$scheme}://{$host}{$path}"
        };

        if ($queryString !== '') {
            $uriString .= '?' . $queryString;
        }

        return new Uri($uriString);
    }

    /**
     * 获取并验证端口
     */
    private static function getValidatedPort(string $scheme): ?int
    {
        if (!isset($_SERVER['SERVER_PORT'])) {
            return null;
        }

        $defaultPort = ($scheme === 'https') ? self::DEFAULT_HTTPS_PORT : self::DEFAULT_HTTP_PORT;
        $portValue = filter_var($_SERVER['SERVER_PORT'], FILTER_VALIDATE_INT, [
            'options' => ['min_range' => self::MIN_PORT_NUMBER, 'max_range' => self::MAX_PORT_NUMBER]
        ]);

        return ($portValue !== false && $portValue !== $defaultPort) ? $portValue : null;
    }

    /**
     * 获取请求头
     */
    private static function getRequestHeaders(): array
    {
        $headers = [];
        $contentHeaders = ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'];

        foreach ($_SERVER as $key => $value) {
            // 使用PHP 8的nullsafe操作符和match表达式
            $headerInfo = match (true) {
                str_starts_with($key, 'HTTP_') => [
                    'name' => self::normalizeHeaderName(substr($key, 5)),
                    'value' => is_scalar($value) ? (string)$value : ''
                ],
                in_array($key, $contentHeaders, true) => [
                    'name' => self::normalizeHeaderName($key),
                    'value' => is_scalar($value) ? (string)$value : ''
                ],
                default => null
            };

            if ($headerInfo !== null) {
                $headers[$headerInfo['name']] = $headerInfo['value'];
            }
        }

        return $headers;
    }

    /**
     * 创建请求体流
     */
    private static function createBody(string $inputContent = ''): Stream
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Failed to create temporary stream');
        }

        if ($inputContent !== '') {
            $written = fwrite($stream, $inputContent);
            if ($written === false || $written !== strlen($inputContent)) {
                fclose($stream);
                throw new \RuntimeException('Failed to write to stream');
            }
            rewind($stream);
        }

        return new Stream($stream);
    }

    /**
     * 解析请求体
     */
    private static function parseRequestBody(string $inputContent = '')
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        if (str_contains($contentType, 'application/json')) {
            if ($inputContent === '' || empty($inputContent)) {
                return [];
            }

            $decoded = json_decode($inputContent, true, 512, JSON_THROW_ON_ERROR);

            return $decoded ?: [];
        }

        return $_POST ?? [];
    }

    /**
     * 创建上传文件对象
     */
    private static function createUploadedFiles(array $files): array
    {
        $uploadedFiles = [];

        foreach ($files as $fieldName => $fileData) {
            if (!is_array($fileData) || !isset($fileData['error'])) {
                continue; // 跳过无效的文件数据
            }

            // 使用PHP 8的match表达式判断单/多文件上传
            $uploadedFiles[$fieldName] = match (true) {
                is_array($fileData['name']) => self::processMultipleFiles($fileData),
                default => self::processSingleFile($fileData)
            };
        }

        return $uploadedFiles;
    }

    /**
     * 处理多文件上传
     */
    private static function processMultipleFiles(array $fileData): array
    {
        $files = [];
        $fileCount = count($fileData['name'] ?? []);

        for ($i = 0; $i < $fileCount; $i++) {
            // 使用PHP 8的nullsafe操作符安全访问数组元素
            $fileInstance = self::createUploadedFileInstance([
                'name' => $fileData['name'][$i] ?? '',
                'type' => $fileData['type'][$i] ?? '',
                'tmp_name' => $fileData['tmp_name'][$i] ?? '',
                'error' => $fileData['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $fileData['size'][$i] ?? 0
            ]);

            if ($fileInstance) {
                $files[] = $fileInstance;
            }
        }

        return $files;
    }

    /**
     * 处理单文件上传
     */
    private static function processSingleFile(array $fileData): ?UploadedFile
    {
        return self::createUploadedFileInstance($fileData);
    }

    /**
     * 创建单个上传文件实例
     */
    private static function createUploadedFileInstance(array $fileData): ?UploadedFile
    {
        $error = $fileData['error'] ?? UPLOAD_ERR_NO_FILE;

        // 使用PHP 8的match表达式处理不同错误情况
        return match ($error) {
            UPLOAD_ERR_NO_FILE => new UploadedFile(
                'php://temp',
                0,
                UPLOAD_ERR_NO_FILE,
                $fileData['name'] ?? ''
            ),
            UPLOAD_ERR_OK => self::createValidFileInstance($fileData),
            default => new UploadedFile(
                'php://temp',
                0,
                $error,
                $fileData['name'] ?? ''
            )
        };
    }

    /**
     * 创建有效的文件实例
     */
    private static function createValidFileInstance(array $fileData): UploadedFile
    {
        $tmpName = $fileData['tmp_name'] ?? '';
        $name = $fileData['name'] ?? '';
        $size = $fileData['size'] ?? 0;
        $type = $fileData['type'] ?? '';

        // 验证临时文件是否存在且可访问
        if (empty($tmpName) || !is_uploaded_file($tmpName)) {
            return new UploadedFile(
                'php://temp',
                0,
                UPLOAD_ERR_CANT_WRITE,
                $name
            );
        }

        return new UploadedFile($tmpName, $size, UPLOAD_ERR_OK, $name, $type);
    }

    /**
     * 清理和验证主机名
     */
    private static function sanitizeHost(string $host): string
    {
        $hostWithoutPort = str_contains($host, ':') ? strstr($host, ':', true) : $host;

        // 使用PHP 8的match表达式进行验证
        $isValid = match (true) {
            preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-\.]*[a-zA-Z0-9]$/', $hostWithoutPort) => true,
            filter_var($hostWithoutPort, FILTER_VALIDATE_IP) !== false => true,
            default => false
        };

        return $isValid ? $host : 'localhost';
    }

    /**
     * 规范化路径
     */
    private static function normalizePath(string $path): string
    {
        $path = rawurldecode($path);

        // 确保路径以 / 开头
        $path = ($path === '' || $path[0] !== '/') ? "/{$path}" : $path;

        // 使用PHP 8的match表达式进行路径标准化
        return match (true) {
            str_contains($path, '//') => preg_replace('#/+#', '/', $path),
            default => $path
        };
    }

    /**
     * 标准化 Header 名称
     */
    private static function normalizeHeaderName(string $header): string
    {
        $header = str_replace('_', '-', strtolower($header));

        // 使用PHP 8的array_map和匿名函数
        return implode('-', array_map(
            fn($part) => ucfirst(strtolower($part)),
            explode('-', $header)
        ));
    }
}
