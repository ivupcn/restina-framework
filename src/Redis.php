<?php
// restina/Redis.php

namespace Restina;

/**
 * Redis 操作类
 * @package Restina
 */
class Redis
{
    private \Redis $connection;
    private array $config;

    /**
     * 构造函数
     */
    public function __construct(Config $config)
    {
        $this->config = $config->get('redis', []);

        // 验证必要配置
        $this->validateConfig();

        $this->connect();
    }

    /**
     * 验证配置参数
     */
    private function validateConfig(): void
    {
        $required = ['host', 'port'];
        foreach ($required as $key) {
            if (!isset($this->config[$key])) {
                throw new \InvalidArgumentException("Redis 配置缺少必需的键: {$key}");
            }
        }

        if (!is_numeric($this->config['port']) || $this->config['port'] < 1 || $this->config['port'] > 65535) {
            throw new \InvalidArgumentException('Redis 端口号必须是 1 到 65535 之间的有效数字');
        }
    }

    /**
     * 连接到 Redis 服务器
     */
    private function connect(): void
    {
        $this->connection = new \Redis();

        $host = $this->config['host'] ?? '127.0.0.1';
        $port = $this->config['port'] ?? 6379;
        $timeout = $this->config['timeout'] ?? 5;
        $database = $this->config['database'] ?? 0;
        $retry_interval = isset($this->config['retry_interval']) ? (float)$this->config['retry_interval'] : 0;
        $read_timeout = $this->config['read_timeout'] ?? 0;

        // 尝试连接
        try {
            $result = $this->connection->connect($host, $port, $timeout, null, 0, $retry_interval, ['read_timeout' => $read_timeout]);

            if (!$result) {
                throw new \RuntimeException('Redis 连接失败');
            }

            // 先认证，再选择数据库
            if (!empty($this->config['password'])) {
                $authResult = $this->connection->auth($this->config['password']);
                if (!$authResult) {
                    throw new \RuntimeException('Redis 认证失败');
                }
            }

            // 选择数据库
            if ($database > 0) {
                $selectResult = $this->connection->select($database);
                if (!$selectResult) {
                    throw new \RuntimeException("无法选择 Redis 数据库: {$database}");
                }
            }

            // 测试连接
            $pingResult = $this->connection->ping();
            if ($pingResult !== '+PONG' && $pingResult !== true) {
                throw new \RuntimeException('Redis 连接测试失败');
            }
        } catch (\Exception $e) {
            throw new \RuntimeException('Redis 连接错误: ' . $e->getMessage());
        }
    }

    /**
     * 检查连接状态
     */
    public function isConnected(): bool
    {
        try {
            return $this->connection->ping() !== false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 重新连接
     */
    public function reconnect(): void
    {
        $this->close();
        $this->connect();
    }

    /**
     * 获取 Redis 连接对象
     */
    public function getConnection(): \Redis
    {
        return $this->connection;
    }

    /**
     * 设置键值
     *
     * @param string $key 键名
     * @param mixed $value 值
     * @param int|null $ttl 过期时间（秒）
     * @return bool
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $serializedValue = $this->serialize($value);

        if ($ttl !== null) {
            return $this->connection->setex($key, $ttl, $serializedValue);
        }

        return $this->connection->set($key, $serializedValue);
    }

    /**
     * 安全序列化数据
     */
    private function serialize(mixed $data): string
    {
        // 使用 JSON 作为默认序列化方式，更安全
        if ($this->config['use_json_serialization'] ?? false) {
            return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        // 如果必须使用 serialize，可以限制类白名单
        return serialize($data);
    }

    /**
     * 安全反序列化数据
     */
    private function unserialize(string $data): mixed
    {
        if ($this->config['use_json_serialization'] ?? false) {
            try {
                return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                return $data; // 回退到原始数据
            }
        }

        // 为了安全，使用 allow_built_in_classes 限制反序列化
        $result = @unserialize($data, ['allowed_classes' => false]);
        return $result === false ? $data : $result;
    }

    /**
     * 获取键值
     *
     * @param string $key 键名
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $result = $this->connection->get($key);

        if ($result === false) {
            return $default;
        }

        return $this->unserialize($result);
    }

    /**
     * 删除键
     *
     * @param string $key 键名
     * @return bool
     */
    public function del(string $key): bool
    {
        return (bool) $this->connection->del($key);
    }

    /**
     * 批量删除键
     *
     * @param array $keys 键名数组
     * @return int 成功删除的键数量
     */
    public function delMultiple(array $keys): int
    {
        return $this->connection->del($keys);
    }

    /**
     * 检查键是否存在
     *
     * @param string $key 键名
     * @return bool
     */
    public function exists(string $key): bool
    {
        return $this->connection->exists($key) > 0;
    }

    /**
     * 设置键的过期时间
     *
     * @param string $key 键名
     * @param int $ttl 过期时间（秒）
     * @return bool
     */
    public function expire(string $key, int $ttl): bool
    {
        return $this->connection->expire($key, $ttl);
    }

    /**
     * 设置键在指定时间戳过期
     *
     * @param string $key 键名
     * @param int $timestamp Unix 时间戳
     * @return bool
     */
    public function expireAt(string $key, int $timestamp): bool
    {
        return $this->connection->expireAt($key, $timestamp);
    }

    /**
     * 获取剩余生存时间
     *
     * @param string $key 键名
     * @return int
     */
    public function ttl(string $key): int
    {
        $ttl = $this->connection->ttl($key);
        // Redis 返回 -2 表示键不存在，-1 表示永不过期
        return $ttl < 0 ? $ttl : $ttl;
    }

    /**
     * 批量设置键值
     *
     * @param array $items 键值对数组
     * @param int|null $ttl 过期时间（秒）
     * @return bool
     */
    public function mset(array $items, ?int $ttl = null): bool
    {
        $serializedItems = [];

        foreach ($items as $key => $value) {
            $serializedItems[$key] = $this->serialize($value);
        }

        $result = $this->connection->mset($serializedItems);

        // 如果设置了过期时间，为每个键单独设置过期时间
        if ($ttl !== null) {
            foreach (array_keys($items) as $key) {
                $this->connection->expire($key, $ttl);
            }
        }

        return $result;
    }

    /**
     * 批量获取键值
     *
     * @param array $keys 键名数组
     * @return array
     */
    public function mget(array $keys): array
    {
        $results = $this->connection->mget($keys);
        $data = [];

        foreach ($keys as $index => $key) {
            $result = $results[$index];

            if ($result === false || $result === null) {
                $data[$key] = null;
            } else {
                $data[$key] = $this->unserialize($result);
            }
        }

        return $data;
    }

    /**
     * 自增
     *
     * @param string $key 键名
     * @param int $step 步长，默认为1
     * @return int
     */
    public function increment(string $key, int $step = 1): int
    {
        return $this->connection->incrBy($key, $step);
    }

    /**
     * 自减
     *
     * @param string $key 键名
     * @param int $step 步长，默认为1
     * @return int
     */
    public function decrement(string $key, int $step = 1): int
    {
        return $this->connection->decrBy($key, $step);
    }

    /**
     * 开始事务
     */
    public function multi(): \Redis
    {
        return $this->connection->multi();
    }

    /**
     * 提交事务
     */
    public function exec(): mixed
    {
        return $this->connection->exec();
    }

    /**
     * 取消事务
     */
    public function discard(): bool
    {
        return $this->connection->discard();
    }

    /**
     * 开始管道操作
     */
    public function pipeline(): \Redis
    {
        return $this->connection->multi(\Redis::PIPELINE);
    }

    /**
     * 清空当前数据库
     *
     * @return bool
     */
    public function flush(): bool
    {
        return $this->connection->flushDB();
    }

    /**
     * 关闭连接
     */
    public function close(): void
    {
        if (method_exists($this->connection, 'close') && $this->isConnected()) {
            $this->connection->close();
        }
    }

    /**
     * 获取 Redis 服务器信息
     */
    public function info(): array
    {
        return $this->connection->info();
    }

    /**
     * 获取 Redis 服务器特定部分的信息
     */
    public function infoSection(?string $section = null): array
    {
        return $this->connection->info($section);
    }

    /**
     * 获取所有键
     */
    public function keys(string $pattern): array
    {
        return $this->connection->keys($pattern);
    }

    /**
     * 获取当前数据库中的键数量
     */
    public function dbSize(): int
    {
        return $this->connection->dbSize();
    }

    /**
     * 保存数据到磁盘
     */
    public function save(): bool
    {
        return $this->connection->save();
    }

    /**
     * 异步保存数据到磁盘
     */
    public function bgsave(): bool
    {
        return $this->connection->bgsave();
    }

    /**
     * 获取客户端连接数
     */
    public function clientList(): array
    {
        return $this->connection->clientList();
    }

    /**
     * 析构函数，确保连接被关闭
     */
    public function __destruct()
    {
        $this->close();
    }
}
