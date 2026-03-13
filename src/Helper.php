<?php
// restina/Helper.php

namespace Restina;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

class Helper
{
    /**
     * 生成指定长度的随机字符串
     * 
     * @param int $length 字符串长度
     * @param string $type 生成类型: 'number'(纯数字), 'alpha'(字母), 'alnum'(字母+数字), 'all'(所有字符)
     * @return string 随机字符串
     */
    public static function generateRandomString(int $length = 8, string $type = 'alnum'): string
    {
        switch ($type) {
            case 'number':
                $characters = '0123456789';
                break;
            case 'alpha':
                $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
                break;
            case 'alnum':
                $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
                break;
            case 'all':
            default:
                $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!@#$%^&*()_+-=[]{}|;:,.<>?';
                break;
        }

        $randomString = '';
        $charLength = strlen($characters);

        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charLength - 1)];
        }

        return $randomString;
    }

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
