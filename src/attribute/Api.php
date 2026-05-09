<?php
// Restina/attribute/Api.php

namespace Restina\attribute;

#[\Attribute(\Attribute::TARGET_METHOD)]
class Api
{
    /**
     * API 标题
     */
    public string $title;

    /**
     * API 描述
     */
    public string $description;

    /**
     * 结果 MIME 类型
     */
    public string $responseMime;

    /**
     * 结果示例
     */
    public mixed $responseExample;

    /**
     * 载荷类型
     */
    public string $payloadType;

    /**
     * 标签
     */
    public array $tags;

    /**
     * 构造函数
     *
     * @param string $title API 标题
     * @param string $description API 描述
     * @param string $responseMime 响应 MIME 类型
     * @param mixed $responseExample 响应示例
     * @param string $payloadType 载荷类型
     * @param array $tags
     */
    public function __construct(
        string $title = '',
        string $description = '',
        string $responseMime = '',
        mixed $responseExample = null,
        string $payloadType = '',
        array $tags = []
    ) {
        $this->title = $title;
        $this->description = $description;
        $this->responseMime = $responseMime;
        $this->responseExample = $responseExample;
        $this->payloadType = $payloadType;
        $this->tags = $tags;
    }
}
