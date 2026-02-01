<?php
// restina/Cache.php

namespace Restina;

class Cache
{
    private $redis = null;  // 使用混合类型，避免 IDE 报错
    private string $cacheDir;
    private bool $useRedis = false;
    private string $prefix = 'restina:';
    private Config $config;
    private string $driver = '';

    /**
     * @param Config $config 配置实例
     */
    public function __construct(Config $config, ?string $cacheDir = null)
    {
        $this->config = $config;
        $this->prefix = $this->config->get('redis.prefix', 'restina:');
        $this->driver = $this->config->get('app.cache', '');

        // 检查Redis扩展是否可用
        if (!extension_loaded('redis')) {
            $this->useRedis = false;
            $this->redis = null;
        } else {
            // 从配置中获取Redis设置
            $redisHost = $this->config->get('redis.host', '127.0.0.1');
            $redisPort = $this->config->get('redis.port', 6379);
            $redisEnabled = $this->driver === 'redis';

            if ($redisEnabled) {
                try {
                    $this->redis = new \Redis();
                    $this->redis->connect($redisHost, $redisPort);

                    // 测试连接
                    if ($this->redis->ping()) {
                        $this->useRedis = true;
                    } else {
                        // 连接失败，回退到文件缓存
                        $this->redis = null;
                        $this->useRedis = false;
                    }
                } catch (\Exception $e) {
                    // Redis连接失败，使用文件缓存
                    $this->redis = null;
                    $this->useRedis = false;
                }
            }
        }

        // 如果不使用Redis，设置文件缓存目录
        if (!$this->useRedis) {
            if ($cacheDir === '') {
                // 如果配置中也没有指定路径，使用默认路径
                $cacheDir = __DIR__ . '/../runtime/cache';
            }

            $this->cacheDir = rtrim($cacheDir, DIRECTORY_SEPARATOR);

            if (!is_dir($this->cacheDir)) {
                mkdir($this->cacheDir, 0755, true);
            }
        }
    }

    /**
     * 设置缓存项
     */
    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        if ($this->driver === '') {
            return;
        }
        if ($this->useRedis) {
            $this->setRedisCache($key, $value, $ttl);
        } else {
            $this->setFileCache($key, $value, $ttl);
        }
    }

    /**
     * 获取缓存项
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if ($this->driver === '') {
            return null;
        }
        if ($this->useRedis) {
            return $this->getRedisCache($key, $default);
        } else {
            return $this->getFileCache($key, $default);
        }
    }

    /**
     * 检查缓存项是否存在
     */
    public function has(string $key): bool
    {
        if ($this->driver === '') {
            return false;
        }
        if ($this->useRedis) {
            return $this->hasRedisCache($key);
        } else {
            return $this->hasFileCache($key);
        }
    }

    /**
     * 删除缓存项
     */
    public function delete(string $key): bool
    {
        if ($this->useRedis) {
            return $this->deleteRedisCache($key);
        } else {
            return $this->deleteFileCache($key);
        }
    }

    /**
     * 清空所有缓存
     */
    public function clear(): void
    {
        if ($this->useRedis) {
            $this->clearRedisCache();
        } else {
            $this->clearFileCache();
        }
    }

    /**
     * 设置Redis缓存
     */
    private function setRedisCache(string $key, mixed $value, int $ttl): void
    {
        $prefixedKey = $this->prefix . $key;
        $this->redis->setex($prefixedKey, $ttl, serialize($value));
    }

    /**
     * 获取Redis缓存
     */
    private function getRedisCache(string $key, mixed $default): mixed
    {
        $prefixedKey = $this->prefix . $key;
        $serializedValue = $this->redis->get($prefixedKey);

        if ($serializedValue !== false) {
            return unserialize($serializedValue);
        }

        return $default;
    }

    /**
     * 检查Redis缓存是否存在
     */
    private function hasRedisCache(string $key): bool
    {
        $prefixedKey = $this->prefix . $key;
        return $this->redis->exists($prefixedKey) > 0;
    }

    /**
     * 删除Redis缓存
     */
    private function deleteRedisCache(string $key): bool
    {
        $prefixedKey = $this->prefix . $key;
        return $this->redis->del($prefixedKey) > 0;
    }

    /**
     * 清空Redis缓存
     */
    private function clearRedisCache(): void
    {
        // 删除所有匹配前缀的键
        $keys = $this->redis->keys($this->prefix . '*');
        if (!empty($keys)) {
            $this->redis->del($keys);
        }
    }

    /**
     * 获取文件缓存的完整路径
     */
    private function getFilePath(string $key): string
    {
        return $this->cacheDir . DIRECTORY_SEPARATOR . md5($key) . '.cache';
    }

    /**
     * 设置文件缓存
     */
    private function setFileCache(string $key, mixed $value, int $ttl): void
    {
        $filePath = $this->getFilePath($key);

        $cacheData = [
            'value' => $value,
            'expires_at' => time() + $ttl
        ];

        file_put_contents($filePath, serialize($cacheData));
    }

    /**
     * 获取文件缓存
     */
    private function getFileCache(string $key, mixed $default): mixed
    {
        $filePath = $this->getFilePath($key);

        if (!file_exists($filePath)) {
            return $default;
        }

        $cacheData = unserialize(file_get_contents($filePath));

        if ($cacheData === false) {
            unlink($filePath); // 删除损坏的缓存文件
            return $default;
        }

        // 检查是否过期
        if ($cacheData['expires_at'] < time()) {
            unlink($filePath); // 删除过期的缓存文件
            return $default;
        }

        return $cacheData['value'];
    }

    /**
     * 检查文件缓存是否存在
     */
    private function hasFileCache(string $key): bool
    {
        $filePath = $this->getFilePath($key);

        if (!file_exists($filePath)) {
            return false;
        }

        $cacheData = unserialize(file_get_contents($filePath));

        if ($cacheData === false) {
            unlink($filePath); // 删除损坏的缓存文件
            return false;
        }

        // 检查是否过期
        if ($cacheData['expires_at'] < time()) {
            unlink($filePath); // 删除过期的缓存文件
            return false;
        }

        return true;
    }

    /**
     * 删除文件缓存
     */
    private function deleteFileCache(string $key): bool
    {
        $filePath = $this->getFilePath($key);

        if (file_exists($filePath)) {
            return unlink($filePath);
        }

        return false;
    }

    /**
     * 清空文件缓存
     */
    private function clearFileCache(): void
    {
        $files = glob($this->cacheDir . DIRECTORY_SEPARATOR . '*.cache');
        foreach ($files as $file) {
            unlink($file);
        }
    }

    /**
     * 获取多个缓存项
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $results = [];

        if ($this->useRedis) {
            $prefixedKeys = [];
            foreach ($keys as $key) {
                $prefixedKeys[] = $this->prefix . $key;
            }

            $values = $this->redis->mget($prefixedKeys);

            $i = 0;
            foreach ($keys as $key) {
                $value = $values[$i] !== false ? unserialize($values[$i]) : $default;
                $results[$key] = $value;
                $i++;
            }
        } else {
            foreach ($keys as $key) {
                $results[$key] = $this->get($key, $default);
            }
        }

        return $results;
    }

    /**
     * 设置多个缓存项
     */
    public function setMultiple(iterable $values, int $ttl = 3600): void
    {
        if ($this->useRedis) {
            $pipeline = $this->redis->multi(\Redis::PIPELINE);

            foreach ($values as $key => $value) {
                $prefixedKey = $this->prefix . $key;
                $pipeline->setex($prefixedKey, $ttl, serialize($value));
            }

            $pipeline->exec();
        } else {
            foreach ($values as $key => $value) {
                $this->set($key, $value, $ttl);
            }
        }
    }

    /**
     * 删除多个缓存项
     */
    public function deleteMultiple(iterable $keys): bool
    {
        if ($this->useRedis) {
            $prefixedKeys = [];
            foreach ($keys as $key) {
                $prefixedKeys[] = $this->prefix . $key;
            }

            return $this->redis->del($prefixedKeys) > 0;
        } else {
            foreach ($keys as $key) {
                $this->delete($key);
            }
            return true;
        }
    }

    /**
     * 清理过期的文件缓存
     */
    public function gc(): void
    {
        if (!$this->useRedis) {
            $files = glob($this->cacheDir . DIRECTORY_SEPARATOR . '*.cache');
            foreach ($files as $file) {
                $cacheData = unserialize(file_get_contents($file));
                if ($cacheData === false || $cacheData['expires_at'] < time()) {
                    unlink($file);
                }
            }
        }
    }

    /**
     * 获取Redis实例（如果可用）
     */
    public function getRedis(): mixed
    {
        return $this->redis;
    }

    /**
     * 检查是否使用Redis
     */
    public function isUsingRedis(): bool
    {
        return $this->useRedis;
    }

    /**
     * 关闭Redis连接
     */
    public function close(): void
    {
        if ($this->redis && $this->useRedis) {
            $this->redis->close();
        }
    }
}
