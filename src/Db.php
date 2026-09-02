<?php
// restina/Db.php

namespace Restina;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Restina\Config;
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
                    // 直接传递给 addConnection，Illuminate ConnectionFactory 会自动检测
                    // read/write 键并创建 ReadWriteConnection，实现透明读写分离
                    $this->capsule->addConnection($connection, $name);
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
