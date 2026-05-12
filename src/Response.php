<?php
// restina/Response.php

namespace Restina;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Nyholm\Psr7\Response as BaseResponse;
use Nyholm\Psr7\Stream;

/**
 * 响应类
 */
class Response extends BaseResponse
{
    /** @var array HTTP状态码与描述映射 */
    private const STATUS_TEXTS = [
        // Informational 1xx
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        103 => 'Early Hints',

        // Success 2xx
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-Status',
        208 => 'Already Reported',
        226 => 'IM Used',

        // Redirection 3xx
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',

        // Client Error 4xx
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Payload Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed',
        418 => 'I\'m a teapot',
        421 => 'Misdirected Request',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',
        425 => 'Too Early',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        451 => 'Unavailable For Legal Reasons',

        // Server Error 5xx
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        510 => 'Not Extended',
        511 => 'Network Authentication Required',
    ];

    /**
     * 构造函数
     *
     * @param int $status HTTP状态码
     * @param array $headers HTTP头
     * @param StreamInterface|string|null $body 响应体
     * @param string $version HTTP协议版本
     * @param string|null $reasonPhrase 状态原因短语
     */
    public function __construct(
        int $status = 200,
        array $headers = [],
        StreamInterface|string|null $body = null,
        string $version = '1.1',
        string|null $reasonPhrase = null
    ) {
        // 如果没有提供原因短语，尝试从预定义列表中获取
        $reasonPhrase ??= self::STATUS_TEXTS[$status] ?? '';

        parent::__construct($status, $headers, $this->prepareBody($body), $version, $reasonPhrase);
    }

    /**
     * 准备响应体
     */
    private function prepareBody(StreamInterface|string|null $body): StreamInterface
    {
        return match (true) {
            $body instanceof StreamInterface => $body,
            is_string($body) => Stream::create($body),
            $body === null => Stream::create(''),
            default => Stream::create((string)$body)
        };
    }

    /**
     * 创建JSON响应
     *
     * @param mixed $data 响应数据
     * @param int $status HTTP状态码
     * @param array $headers 额外的HTTP头
     * @param int $encodingOptions JSON编码选项
     * @return static
     */
    public static function json(
        mixed $data,
        int $status = 200,
        array $headers = [],
        int $encodingOptions = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ): static {
        $encodedData = json_encode($data, $encodingOptions);

        if ($encodedData === false) {
            throw new \JsonException('JSON encoding failed: ' . json_last_error_msg());
        }

        $headers = array_merge(['Content-Type' => 'application/json'], $headers);

        return new static($status, $headers, $encodedData);
    }

    /**
     * 创建HTML响应
     *
     * @param string $html HTML内容
     * @param int $status HTTP状态码
     * @param array $headers 额外的HTTP头
     * @return static
     */
    public static function html(string $html, int $status = 200, array $headers = []): static
    {
        $headers = array_merge(['Content-Type' => 'text/html; charset=utf-8'], $headers);

        return new static($status, $headers, $html);
    }

    /**
     * 创建文本响应
     *
     * @param string $text 文本内容
     * @param int $status HTTP状态码
     * @param array $headers 额外的HTTP头
     * @return static
     */
    public static function text(string $text, int $status = 200, array $headers = []): static
    {
        $headers = array_merge(['Content-Type' => 'text/plain; charset=utf-8'], $headers);

        return new static($status, $headers, $text);
    }

    /**
     * 创建重定向响应
     *
     * @param string $url 重定向URL
     * @param int $status HTTP状态码，默认302
     * @param array $headers 额外的HTTP头
     * @return static
     */
    public static function redirect(string $url, int $status = 302, array $headers = []): static
    {
        $headers = array_merge(['Location' => $url], $headers);

        return new static($status, $headers);
    }

    /**
     * 创建文件下载响应
     *
     * @param string $filePath 文件路径
     * @param string|null $fileName 下载时的文件名
     * @param array $headers 额外的HTTP头
     * @return static
     */
    public static function download(string $filePath, string $fileName = null, array $headers = []): static
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("File does not exist: {$filePath}");
        }

        if (!is_readable($filePath)) {
            throw new \InvalidArgumentException("File is not readable: {$filePath}");
        }

        $fileSize = filesize($filePath);
        $fileName = $fileName ?: basename($filePath);

        $headers = array_merge([
            'Content-Type' => self::detectMimeType($filePath),
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            'Content-Length' => (string)$fileSize,
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0'
        ], $headers);

        $fileStream = Stream::create(fopen($filePath, 'rb'));

        return new static(200, $headers, $fileStream);
    }

    /**
     * 创建API响应（遵循RESTful API标准）
     *
     * @param mixed $data 响应数据
     * @param int $status HTTP状态码
     * @param string $message 状态消息
     * @param array $headers 额外的HTTP头
     * @param int $encodingOptions JSON编码选项
     * @return static
     */
    public static function api(
        mixed $data,
        int $status = 200,
        string $message = 'Success',
        array $headers = [],
        int $encodingOptions = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ): static {
        $responseData = [
            'success' => $status < 400,
            'status' => $status,
            'message' => $message,
            'data' => $data,
            'timestamp' => date('c')
        ];

        return self::json($responseData, $status, $headers, $encodingOptions);
    }

    /**
     * 创建错误API响应
     *
     * @param string $message 错误消息
     * @param int $status HTTP状态码
     * @param mixed $details 额外错误详情
     * @param array $headers 额外的HTTP头
     * @param int $encodingOptions JSON编码选项
     * @return static
     */
    public static function error(
        string $message,
        int $status = 400,
        mixed $details = null,
        array $headers = [],
        int $encodingOptions = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ): static {
        $errorData = [
            'success' => false,
            'status' => $status,
            'message' => $message,
            'error' => $details,
            'timestamp' => date('c')
        ];

        return self::json($errorData, $status, $headers, $encodingOptions);
    }

    /**
     * 检测MIME类型
     */
    private static function detectMimeType(string $filePath): string
    {
        if (function_exists('mime_content_type')) {
            return mime_content_type($filePath) ?: 'application/octet-stream';
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'txt' => 'text/plain',
            'htm', 'html' => 'text/html',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'pdf' => 'application/pdf',
            'zip' => 'application/zip',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            default => 'application/octet-stream'
        };
    }

    /**
     * 获取状态码对应的原因短语
     */
    public static function getReasonPhraseFor(int $statusCode): string
    {
        return self::STATUS_TEXTS[$statusCode] ?? 'Unknown Status Code';
    }

    /**
     * 检查状态码是否为成功状态
     */
    public function isSuccessful(): bool
    {
        return $this->getStatusCode() >= 200 && $this->getStatusCode() < 300;
    }

    /**
     * 检查状态码是否为重定向状态
     */
    public function isRedirect(): bool
    {
        $code = $this->getStatusCode();
        return $code >= 300 && $code < 400;
    }

    /**
     * 检查状态码是否为客户端错误
     */
    public function isClientError(): bool
    {
        $code = $this->getStatusCode();
        return $code >= 400 && $code < 500;
    }

    /**
     * 检查状态码是否为服务端错误
     */
    public function isServerError(): bool
    {
        $code = $this->getStatusCode();
        return $code >= 500 && $code < 600;
    }

    /**
     * 创建带有新状态码的副本
     */
    public function withStatus($code, $reasonPhrase = ''): static
    {
        $new = clone $this;
        $new->response = $this->response->withStatus($code, $reasonPhrase ?: self::getReasonPhraseFor($code));
        return $new;
    }
}
