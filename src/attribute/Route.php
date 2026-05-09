<?php
// Restina/attribute/Route.php

namespace Restina\attribute;

#[\Attribute(\Attribute::TARGET_METHOD)]
class Route
{
    /**
     * HTTP方法数组
     * 
     * @var array|string
     */
    public array|string $methods;

    /**
     * 路由路径
     * 
     * @var string
     */
    public string $route;

    /**
     * 路由代码
     * 
     * @var string
     */
    public string $code;

    /**
     * 是否需要权限（默认 true）
     * 
     * @var bool
     */
    public bool $permission;

    /**
     * 是否需要JWT认证（默认 true）
     * 
     * @var bool
     */
    public bool $jwt;

    /**
     * 是否自动刷新Token（仅在JWT认证且Token过期时有效，默认 false）
     * 
     * @var bool
     */
    public bool $autoRefreshToken = false;

    /**
     * 构造函数
     *
     * @param array $methods HTTP方法数组，例如 ['GET', 'POST']
     * @param string $route 路由路径
     * @param string $code 路由代码
     * @param bool $permission 是否需要权限（默认 true）
     * @param bool $jwt 是否需要JWT认证（默认 true）
     * @param bool $autoRefreshToken 是否自动刷新Token（默认 false）
     */
    public function __construct(
        array|string $methods = ['GET'],
        string $route = '',
        string $code = '',
        bool $permission = true,
        bool $jwt = true,
        bool $autoRefreshToken = false
    ) {
        $this->methods = $methods;
        $this->route = $route;
        $this->code = $code;
        $this->permission = $permission;
        $this->jwt = $jwt;
        $this->autoRefreshToken = $autoRefreshToken;
    }

    /**
     * 获取HTTP方法
     * 
     * @return array|string
     */
    public function getMethods(): array|string
    {
        return $this->methods;
    }

    /**
     * 获取路由路径
     * 
     * @return string
     */
    public function getRoute(): string
    {
        return $this->route;
    }

    /**
     * 获取路由代码
     * 
     * @return string
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * 获取是否需要权限
     * 
     * @return bool
     */
    public function getPermission(): bool
    {
        return $this->permission;
    }

    /**
     * 获取是否需要JWT认证
     * 
     * @return bool
     */
    public function getJWT(): bool
    {
        return $this->jwt;
    }

    /**
     * 获取是否自动刷新Token
     * 
     * @return bool
     */
    public function getAutoRefreshToken(): bool
    {
        return $this->autoRefreshToken;
    }

    /**
     * 检查是否包含指定的HTTP方法
     * 
     * @param string $method HTTP方法
     * @return bool
     */
    public function hasMethod(string $method): bool
    {
        // 将方法转换为数组进行比较
        $methodsArray = is_array($this->methods) ? $this->methods : [$this->methods];
        return in_array(strtoupper($method), array_map('strtoupper', $methodsArray));
    }

    /**
     * 获取标准化的方法数组
     * 
     * @return array
     */
    public function getMethodsAsArray(): array
    {
        return is_array($this->methods) ? $this->methods : [$this->methods];
    }
}
