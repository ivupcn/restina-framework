<?php
// restina/Config.php

namespace Restina;

class Config
{
    private array $config = [];

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * 获取配置项
     * 
     * @param string|null $key 配置键名，支持点号分隔的嵌套格式，如 'database.host'
     * @param mixed $default 默认值
     * @return mixed
     */
    public function get(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->config;
        }

        // 如果配置为空，直接返回默认值
        if (empty($this->config)) {
            return $default;
        }

        // 使用点号分割键名
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * 设置配置项
     * 
     * @param string $key 配置键名，支持点号分隔的嵌套格式
     * @param mixed $value 配置值
     */
    public function set(string $key, mixed $value): void
    {
        $keys = explode('.', $key);
        $configRef = &$this->config;

        $lastKey = array_pop($keys);

        foreach ($keys as $k) {
            if (!isset($configRef[$k]) || !is_array($configRef[$k])) {
                $configRef[$k] = [];
            }
            $configRef = &$configRef[$k];
        }

        $configRef[$lastKey] = $value;
    }

    /**
     * 检查配置项是否存在
     * 
     * @param string $key 配置键名，支持点号分隔的嵌套格式
     * @return bool
     */
    public function has(string $key): bool
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return false;
            }
            $value = $value[$k];
        }

        return true;
    }

    /**
     * 批量设置配置项
     */
    public function setMany(array $configs): void
    {
        foreach ($configs as $key => $value) {
            $this->set($key, $value);
        }
    }

    /**
     * 获取所有配置
     */
    public function all(): array
    {
        return $this->config;
    }

    /**
     * 清空配置
     */
    public function clear(): void
    {
        $this->config = [];
    }

    /**
     * 移除配置项
     */
    public function remove(string $key): void
    {
        $keys = explode('.', $key);
        $configRef = &$this->config;

        $lastKey = array_pop($keys);

        foreach ($keys as $k) {
            if (!isset($configRef[$k]) || !is_array($configRef[$k])) {
                return;
            }
            $configRef = &$configRef[$k];
        }

        unset($configRef[$lastKey]);
    }
}
