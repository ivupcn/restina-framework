<?php
// restina/Model.php

namespace Restina;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Builder;
use Restina\App;

/**
 * ORM模型基类
 */
class Model extends EloquentModel
{
    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        // 如果模型没有指定连接，则尝试从全局获取
        if (!$this->connection) {
            $app = App::init();
            if ($app->isBootstrapped()) {
                // 注意：这里应该是Db类而不是DatabaseManager
                $dbManager = $app->resolve(Db::class);
                if ($dbManager) {
                    // 可以在这里设置默认连接，或者让模型使用配置中的默认连接
                    $this->connection = $this->connection ?: $app->getConfig('database.default', 'mysql');
                }
            }
        }
    }

    /**
     * 创建查询构建器的新实例
     */
    public function newEloquentBuilder($query)
    {
        return new Builder($query);
    }

    /**
     * 重写父类的on方法，使其与Eloquent兼容
     */
    public static function on($connection = null)
    {
        // 调用父类的on方法以确保兼容性
        return parent::on($connection);
    }

    /**
     * 在指定的连接上运行查询
     */
    public static function onConnection(string $connectionName): \Illuminate\Database\Eloquent\Builder
    {
        return static::on($connectionName);
    }
}
