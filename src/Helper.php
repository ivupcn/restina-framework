<?php
// restina/Helper.php

namespace Restina;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

class Helper
{
    /**
     * 创建HTML响应
     */
    public static function createHtmlResponse(string $html): ResponseInterface
    {
        $response = new Response();
        $response->getBody()->write($html);
        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * 创建JSON响应
     */
    public static function createJsonResponse(array $data): ResponseInterface
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response = new Response();
        $response->getBody()->write($json);
        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    /**
     * 创建文本响应
     */
    public static function createTextResponse(string $text): ResponseInterface
    {
        $response = new Response();
        $response->getBody()->write($text);
        return $response
            ->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }
}
