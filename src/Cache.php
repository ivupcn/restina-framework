<?php
// restina/Cache.php

namespace Restina;

/**
 * 缓存类
 * @package Restina
 */
class Cache
{
    /**
     * Redis 实例
     * @var Redis
     */
    private ?Redis $redis;

    /**
     * 缓存目录
     * @var string
     */
    private string $cacheDir;

    /**
     * 是否使用 Redis
     * @var bool
     */
    private bool $useRedis = false;

    /**
     * Redis 缓存前缀
     * @var string
     */
    private string $prefix = 'restina:';

    /**
     * 配置
     * @var Config
     */
    private Config $config;

    /**
     * 驱动
     * @var string
     */
    private string $driver = '';

    /**
     * 垃圾回收锁文件
     * @var string
     */
    private string $gcLockFile;

    /**
     * 垃圾回收概率
     * @var float
     */
    private float $gcProbability = 0.01;

    /**
     * @param Config $config 配置实例
     * @param string|null $cacheDir 缓存目录
     */
    public function __construct(Config $config, ?string $cacheDir = null)
    {
        $this->config = $config;
        $this->prefix = $this->config->get('redis.prefix', 'restina:');
        $this->driver = $this->config->get('app.cache', 'file');

        // 检查Redis扩展是否可用
        if (!extension_loaded('redis')) {
            $this->useRedis = false;
            $this->redis = null;
        } else {
            // 从配置中获取Redis设置
            $redisEnabled = $this->driver === 'redis';
            if ($redisEnabled) {
                try {
                    $this->redis = new Redis($config);

                    // 在 FrankenPHP 环境中，可能需要更严格的连接测试
                    if (RUN_MODE === 'worker') {
                        // 可能需要配置持久连接或连接池
                        $this->testAndReconnect();
                    } else {
                        // 测试连接
                        $this->redis->getConnection()->ping();
                    }

                    $this->useRedis = true;
                } catch (\Exception $e) {
                    // Redis连接失败，使用文件缓存
                    error_log("Redis connection failed: " . $e->getMessage());
                    $this->redis = null;
                    $this->useRedis = false;
                }
            }
        }

        // 如果不使用Redis，设置文件缓存目录
        if (!$this->useRedis) {
            if ($cacheDir === null) {
                // 如果参数未指定，尝试从配置获取
                $cacheDir = $this->config->get('app.cache_dir', '');
                if ($cacheDir === '') {
                    // 如果配置中也没有指定路径，使用默认路径
                    $cacheDir = __DIR__ . '/../runtime/cache';
                }
            }
            $this->cacheDir = rtrim($cacheDir, DIRECTORY_SEPARATOR);
            if (!is_dir($this->cacheDir)) {
                mkdir($this->cacheDir, 0755, true);
            }
            $this->gcLockFile = $this->cacheDir . DIRECTORY_SEPARATOR . '.gc_lock';
        }
    }

    /**
     * 异步概率性清理过期文件缓存
     */
    private function scheduleGc(): void
    {
        // 只在文件缓存模式下执行
        if ($this->useRedis) {
            return;
        }

        // 以一定概率检查是否需要执行 GC
        if (mt_rand(1, 10000) <= ($this->gcProbability * 10000)) {
            $this->performGcIfNotRunning();
        }
    }

    /**
     * 检查 GC 是否正在运行，如果没有则执行
     */
    private function performGcIfNotRunning(): void
    {
        $lockFile = $this->gcLockFile;

        // 尝试获取锁
        $fp = fopen($lockFile, 'w');
        if (!$fp) {
            return; // 无法创建锁文件，跳过本次 GC
        }

        // 使用文件锁确保只有一个进程执行 GC
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return; // 其他进程正在执行 GC，跳过
        }

        try {
            // 再次确认时间间隔，避免频繁执行
            $lastGcTime = file_exists($lockFile . '.timestamp')
                ? (int)file_get_contents($lockFile . '.timestamp')
                : 0;

            // 最少间隔5分钟执行一次 GC
            if (time() - $lastGcTime < 300) {
                return;
            }

            // 记录开始时间
            file_put_contents($lockFile . '.timestamp', (string)time());

            // 执行实际的 GC 操作
            $this->doGc();
        } finally {
            // 释放锁
            flock($fp, LOCK_UN);
            fclose($fp);
            unlink($lockFile);
        }
    }

    /**
     * 执行实际的垃圾回收
     */
    private function doGc(): void
    {
        $files = glob($this->cacheDir . DIRECTORY_SEPARATOR . '*.cache');
        if (!$files) {
            return;
        }

        $now = time();
        $batchSize = 50; // 分批处理，避免长时间占用
        $count = 0;

        foreach ($files as $file) {
            if (++$count % $batchSize === 0) {
                usleep(1000); // 每处理一批暂停1ms，减少系统负载
            }

            if (file_exists($file)) {
                $cacheData = @unserialize(file_get_contents($file));
                if ($cacheData === false || $cacheData['expires_at'] < $now) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * 在 FrankenPHP 环境中测试连接并重连（如果需要）
     */
    private function testAndReconnect(): void
    {
        if (!$this->useRedis || !$this->redis) {
            return;
        }

        try {
            $connection = $this->redis->getConnection();

            // 尝试 ping 服务器
            $connection->ping();
        } catch (\Exception $e) {
            // 连接失败，尝试重连
            try {
                // 关闭旧连接
                $this->redis->close();

                // 重新创建连接
                $this->redis = new Redis($this->config);

                // 测试新连接
                $this->redis->getConnection()->ping();
            } catch (\Exception $reconnectException) {
                // 重连失败，切换到文件缓存
                error_log("Redis reconnection failed: " . $reconnectException->getMessage());
                $this->useRedis = false;
                $this->redis = null;
            }
        }
    }

    /**
     * 设置缓存项
     */
    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        if ($this->driver === '') {
            return false;
        }

        // 在 FrankenPHP 环境中，检查 Redis 连接状态
        if (RUN_MODE === 'worker' && $this->useRedis) {
            $this->testAndReconnect();
        }

        if ($this->useRedis) {
            return $this->setRedisCache($key, $value, $ttl);
        } else {
            $this->setFileCache($key, $value, $ttl);
            return true;
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

        // 在 FrankenPHP 环境中，检查 Redis 连接状态
        if (RUN_MODE === 'worker' && $this->useRedis) {
            $this->testAndReconnect();
        }

        if ($this->useRedis) {
            return $this->getRedisCache($key, $default);
        } else {
            $result = $this->getFileCache($key, $default);
            $this->scheduleGc(); // 调度异步 GC
            return $result;
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

        // 在 FrankenPHP 环境中，检查 Redis 连接状态
        if (RUN_MODE === 'worker' && $this->useRedis) {
            $this->testAndReconnect();
        }

        if ($this->useRedis) {
            return $this->hasRedisCache($key);
        } else {
            $result = $this->hasFileCache($key);
            $this->scheduleGc(); // 调度异步 GC
            return $result;
        }
    }

    /**
     * 删除缓存项
     */
    public function delete(string $key): bool
    {
        // 在 FrankenPHP 环境中，检查 Redis 连接状态
        if (RUN_MODE === 'worker' && $this->useRedis) {
            $this->testAndReconnect();
        }

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
        // 在 FrankenPHP 环境中，检查 Redis 连接状态
        if (RUN_MODE === 'worker' && $this->useRedis) {
            $this->testAndReconnect();
        }

        if ($this->useRedis) {
            $this->clearRedisCache();
        } else {
            $this->clearFileCache();
        }
    }

    /**
     * 获取多个缓存项
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        // 在 FrankenPHP 环境中，检查 Redis 连接状态
        if (RUN_MODE === 'worker' && $this->useRedis) {
            $this->testAndReconnect();
        }

        $results = [];

        if ($this->useRedis) {
            $prefixedKeys = [];
            $keyMap = [];

            // 构建带前缀的键数组和映射关系
            foreach ($keys as $key) {
                $prefixedKey = $this->prefix . $key;
                $prefixedKeys[] = $prefixedKey;
                $keyMap[$prefixedKey] = $key;
            }

            // 批量获取
            $fetched = $this->redis->mget($prefixedKeys);

            // 重组结果
            $i = 0;
            foreach ($prefixedKeys as $prefixedKey) {
                $originalKey = $keyMap[$prefixedKey];
                $results[$originalKey] = $fetched[$i] ?? $default;
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
    public function setMultiple(iterable $values, int $ttl = 3600): bool
    {
        // 在 FrankenPHP 环境中，检查 Redis 连接状态
        if (RUN_MODE === 'worker' && $this->useRedis) {
            $this->testAndReconnect();
        }

        if ($this->useRedis) {
            $prefixedValues = [];
            foreach ($values as $key => $value) {
                $prefixedValues[$this->prefix . $key] = $value;
            }

            // 对于Redis，我们需要手动设置TTL
            $result = $this->redis->mset($prefixedValues);

            // 为每个键设置过期时间
            foreach ($values as $key => $value) {
                $this->redis->expire($this->prefix . $key, $ttl);
            }

            return $result;
        } else {
            $success = true;
            foreach ($values as $key => $value) {
                if (!$this->set($key, $value, $ttl)) {
                    $success = false;
                }
            }
            return $success;
        }
    }

    /**
     * 删除多个缓存项
     */
    public function deleteMultiple(iterable $keys): bool
    {
        // 在 FrankenPHP 环境中，检查 Redis 连接状态
        if (RUN_MODE === 'worker' && $this->useRedis) {
            $this->testAndReconnect();
        }

        if ($this->useRedis) {
            $prefixedKeys = array_map(function ($key) {
                return $this->prefix . $key;
            }, is_array($keys) ? $keys : iterator_to_array($keys));

            return $this->redis->del(...$prefixedKeys) > 0;
        } else {
            $success = true;
            foreach ($keys as $key) {
                if (!$this->delete($key)) {
                    $success = false;
                }
            }
            return $success;
        }
    }

    /**
     * 设置Redis缓存
     */
    private function setRedisCache(string $key, mixed $value, int $ttl): bool
    {
        $prefixedKey = $this->prefix . $key;
        return $this->redis->set($prefixedKey, $value, $ttl);
    }

    /**
     * 获取Redis缓存
     */
    private function getRedisCache(string $key, mixed $default): mixed
    {
        $prefixedKey = $this->prefix . $key;
        $result = $this->redis->get($prefixedKey);
        return $result !== false ? $result : $default;
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
        // 获取所有匹配前缀的键
        $pattern = $this->prefix . '*';

        // 使用scan命令避免阻塞，适用于生产环境
        $iterator = null;
        do {
            $keys = $this->redis->getConnection()->scan($iterator, $pattern, 100);
            if ($keys !== false && !empty($keys)) {
                $this->redis->del(...$keys);
            }
        } while ($iterator > 0);
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
            'expires_at' => time() + $ttl,
            'created_at' => time()
        ];

        // 创建临时文件然后重命名，保证原子性
        $tempFile = $filePath . '.tmp';
        $serialized = serialize($cacheData);

        if (file_put_contents($tempFile, $serialized, LOCK_EX) !== false) {
            rename($tempFile, $filePath);
        }
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

        $cacheData = unserialize(file_get_contents($filePath), ['allowed_classes' => false]);

        if ($cacheData === false || !is_array($cacheData)) {
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

        $cacheData = unserialize(file_get_contents($filePath), ['allowed_classes' => false]);

        if ($cacheData === false || !is_array($cacheData)) {
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

        return true; // 文件不存在也认为删除成功
    }

    /**
     * 清空文件缓存
     */
    private function clearFileCache(): void
    {
        $files = glob($this->cacheDir . DIRECTORY_SEPARATOR . '*.cache');
        if ($files) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
    }

    /**
     * 清理过期的文件缓存
     */
    public function gc(): void
    {
        if (!$this->useRedis) {
            $files = glob($this->cacheDir . DIRECTORY_SEPARATOR . '*.cache');
            if ($files) {
                foreach ($files as $file) {
                    if (file_exists($file)) {
                        $cacheData = unserialize(file_get_contents($file));
                        if ($cacheData === false || $cacheData['expires_at'] < time()) {
                            unlink($file);
                        }
                    }
                }
            }
        }
    }

    /**
     * 获取Redis实例（如果可用）
     */
    public function getRedis(): ?Redis
    {
        return $this->useRedis ? $this->redis : null;
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
