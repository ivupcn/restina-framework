<?php
// restina/Db.php

namespace Restina;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Restina\Config;
use InvalidArgumentException;
use Exception;

/**
 * 数据库管理类
 * @package Restina
 */
class Db
{
    /**
     * @var Capsule
     */
    private Capsule $capsule;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * Db 构造函数
     * @param Config $config
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->capsule = new Capsule();

        $this->setupConnections();
        $this->setupEloquent();
    }

    /**
     * 设置数据库连接
     */
    private function setupConnections(): void
    {
        $dbConfig = $this->config->get('database', []);
        $connections = $dbConfig['connections'] ?? [];

        if (!empty($connections) && is_array($connections)) {
            foreach ($connections as $name => $connection) {
                if (!is_string($name) || !is_array($connection)) {
                    continue; // 跳过无效配置
                }

                // 验证必要配置项
                if (empty($connection['driver'])) {
                    continue; // 忽略没有驱动的配置
                }

                try {
                    if (isset($connection['read']) && isset($connection['write'])) {
                        $this->setupReadWriteConnection($name, $connection);
                    } else {
                        $this->capsule->addConnection($connection, $name);
                    }
                } catch (Exception $e) {
                    error_log("添加数据库连接 '{$name}' 失败: " . $e->getMessage());
                }
            }
        } else {
            // 如果没有配置多个连接，使用简化配置
            $defaultConfig = $this->buildDefaultConnectionConfig($dbConfig);

            try {
                $this->capsule->addConnection($defaultConfig, 'mysql');
            } catch (Exception $e) {
                error_log("添加默认数据库连接失败:" . $e->getMessage());
            }
        }

        // 设置为全局可用
        $this->capsule->setAsGlobal();
    }

    /**
     * 构建默认连接配置
     *
     * @param array $dbConfig
     * @return array
     */
    private function buildDefaultConnectionConfig(array $dbConfig): array
    {
        return [
            'driver' => $dbConfig['driver'] ?? 'mysql',
            'host' => $dbConfig['host'] ?? 'localhost',
            'port' => $dbConfig['port'] ?? 3306,
            'database' => $dbConfig['database'] ?? '',
            'username' => $dbConfig['username'] ?? '',
            'password' => $dbConfig['password'] ?? '',
            'charset' => $dbConfig['charset'] ?? 'utf8mb4',
            'collation' => $dbConfig['collation'] ?? 'utf8mb4_unicode_ci',
            'prefix' => $dbConfig['prefix'] ?? '',
            'strict' => $dbConfig['strict'] ?? true,
            'engine' => $dbConfig['engine'] ?? null,
            'options' => $dbConfig['options'] ?? [],
        ];
    }

    /**
     * 设置读写分离连接
     *
     * @param string $name
     * @param array $config
     * @throws InvalidArgumentException
     */
    private function setupReadWriteConnection(string $name, array $config): void
    {
        // 验证配置结构
        if (empty($config['write']) || !is_array($config['write'])) {
            throw new InvalidArgumentException("读写分离连接 '{$name}' 必须包含 'write' 配置");
        }
        if (empty($config['read']) || !is_array($config['read'])) {
            throw new InvalidArgumentException("读写分离连接 '{$name}' 必须包含 'read' 配置");
        }

        // 提取公共配置项（去除 read/write 特有部分）
        $baseConfig = $config;
        unset($baseConfig['read'], $baseConfig['write']);

        // --- 处理写连接 (Write) ---
        $writeHosts = $config['write'];

        // 判断是单个配置还是数组配置
        $firstWrite = is_array($writeHosts) && isset($writeHosts[0]) ? $writeHosts[0] : $writeHosts;

        // 使用智能合并
        $writeConfig = $this->mergeConfig($baseConfig, $firstWrite);

        // 强制标记为写连接（可选，用于调试）
        $writeConfig['prefix'] = ($writeConfig['prefix'] ?? '') . 'write_';

        // 添加写连接，使用 {$name}_write 作为键名，避免与读连接冲突
        $this->capsule->addConnection($writeConfig, "{$name}_write");

        // --- 处理读连接 (Read) ---
        $readHosts = $config['read'];

        foreach ($readHosts as $index => $readHost) {
            if (!is_array($readHost)) {
                continue; // 跳过无效配置
            }

            // 智能合并
            $readConfig = $this->mergeConfig($baseConfig, $readHost);

            // 添加读连接，使用 {$name}_read_{$index} 作为键名
            $this->capsule->addConnection($readConfig, "{$name}_read_{$index}");
        }
    }

    /**
     * 智能合并配置数组，只覆盖非空值
     * @param array $parent 父级配置（基础配置）
     * @param array $child  子级配置（覆盖配置）
     * @return array
     */
    private function mergeConfig(array $parent, array $child): array
    {
        $result = $parent;

        foreach ($child as $key => $value) {
            // 只有当值不为 null 且不为空字符串时才覆盖
            // 注意：0 是有效值，不能被过滤掉
            if ($value !== null && $value !== '') {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * 设置 Eloquent ORM 实例
     */
    private function setupEloquent(): void
    {
        $this->capsule->bootEloquent();
    }

    /**
     * 获取 Capsule 实例
     *
     * @return Capsule
     */
    public function getCapsule(): Capsule
    {
        return $this->capsule;
    }

    /**
     * 获取连接实例
     *
     * @param string|null $name
     * @return Connection
     */
    public function getConnection(?string $name = null): Connection
    {
        if ($name === null) {
            $default = $this->config->get('database.default', 'mysql');
            return $this->capsule->getConnection($default);
        }

        return $this->capsule->getConnection($name);
    }

    /**
     * 获取所有连接
     *
     * @return array
     */
    public function getConnections(): array
    {
        return $this->capsule->getContainer()->get('db.factory')->getConnections();
    }

    /**
     * 检查连接是否存在
     *
     * @param string $name
     * @return bool
     */
    public function hasConnection(string $name): bool
    {
        return $this->capsule->getContainer()->get('db.factory')->getConnectionResolver()
            ->getConnection($name) !== null;
    }

    /**
     * 获取默认连接名称
     *
     * @return string
     */
    public function getDefaultConnection(): string
    {
        return $this->config->get('database.default', 'mysql');
    }
}
