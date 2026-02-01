<?php
// restina/Db.php

namespace Restina;

use Illuminate\Database\Capsule\Manager as Capsule;
use Restina\Config;

class Db
{
    private Capsule $capsule;
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->capsule = new Capsule();

        $this->setupConnections();
        $this->setupEloquent();
    }

    private function setupConnections(): void
    {
        $dbConfig = $this->config->get('database', []);
        $connections = $dbConfig['connections'] ?? [];
        $defaultConnection = $dbConfig['default'] ?? 'mysql';

        if (!empty($connections)) {
            foreach ($connections as $name => $connection) {
                // 处理读写分离配置
                if (isset($connection['read'])) {
                    $this->setupReadWriteConnection($name, $connection);
                } else {
                    $this->capsule->addConnection($connection, $name);
                }
            }
        } else {
            // 如果没有配置多个连接，使用简化配置
            $defaultConfig = [
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
            ];

            $this->capsule->addConnection($defaultConfig, 'mysql');
        }

        // 设置为全局可用
        $this->capsule->setAsGlobal();
    }

    private function setupReadWriteConnection(string $name, array $config): void
    {
        // 分离读写配置
        $writeConfig = array_merge($config, $config['write'][0] ?? []);
        unset($writeConfig['read'], $writeConfig['write']);

        $this->capsule->addConnection($writeConfig, "{$name}_write");

        foreach ($config['read'] as $index => $readHost) {
            $readConfig = array_merge($config, $readHost);
            unset($readConfig['read'], $readConfig['write']);
            $this->capsule->addConnection($readConfig, "{$name}_read_{$index}");
        }
    }

    private function setupEloquent(): void
    {
        $this->capsule->bootEloquent();
    }

    public function getCapsule(): Capsule
    {
        return $this->capsule;
    }

    public function getConnection(?string $name = null)
    {
        if ($name === null) {
            $default = $this->config->get('database.default', 'mysql');
            return $this->capsule->getConnection($default);
        }

        return $this->capsule->getConnection($name);
    }

    public function getConnections(): array
    {
        $connections = [];
        foreach ($this->capsule->getContainer()->get('db.factory')->getConnections() as $name => $connection) {
            $connections[$name] = $connection;
        }
        return $connections;
    }
}
