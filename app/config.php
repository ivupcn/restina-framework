<?php

return [
    'app' => [
        'name' => 'Restina API Framework',
        'debug' => true,
        'timezone' => 'Asia/Shanghai',
        'cache' => 'file' // 'file' or 'redis'
    ],
    'jwt' => [
        'secret' => 'jwt-key-here',
        'algorithm' => 'HS256',
        'expire_time' => 3600, // 1小时过期
        'refresh_expire_time' => 7200  // 2小时内可刷新
    ],
    'database' => [
        'default' => 'mysql', // 默认连接
        'connections' => [
            'mysql' => [ // 主数据库
                'driver' => 'mysql',
                'host' => 'localhost',
                'port' => 3306,
                'database' => 'main_db',
                'username' => 'root',
                'password' => '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ],
            'pgsql' => [ // PostgreSQL 数据库
                'driver' => 'pgsql',
                'host' => 'localhost',
                'port' => 5432,
                'database' => 'pg_db',
                'username' => 'postgres',
                'password' => '',
                'charset' => 'utf8',
                'prefix' => '',
                'schema' => 'public',
            ],
            'read_write' => [ // 读写分离配置示例
                'driver' => 'mysql',
                'write' => [
                    'host' => ['primary-db-server'],
                ],
                'read' => [
                    'host' => ['replica-db-server-1', 'replica-db-server-2'],
                ],
                'sticky' => true,
                'port' => 3306,
                'database' => 'rw_db',
                'username' => 'root',
                'password' => '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
            ]
        ]
    ],
    'redis' => [
        'host' => '127.0.0.1',
        'port' => 6379,
        'database' => 0,
        'prefix' => 'restina:'
    ],
    'queue' => [
        'default' => 'redis', // 'redis' or 'database'
        'connections' => [
            'redis' => [
                'driver' => 'redis',
                'connection' => 'default',
                'queue' => 'default',
                'retry_after' => 90,
                'block_for' => null,
                'after_commit' => false,
                'prefix' => 'restina:queue:',
                'failed_key' => 'restina:queue:failed'
            ],
            'database' => [
                'driver' => 'database',
                'table' => 'queue_jobs',
                'failed_table' => 'queue_failed_jobs',
                'retry_after' => 90,
                'after_commit' => false
            ]
        ],
        'failed' => [
            'driver' => 'database', // 'database' or 'redis'
            'database' => 'mysql',
            'table' => 'queue_failed_jobs',
        ],
    ],
];
