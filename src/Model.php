<?php
// restina/Model.php

namespace Restina;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Builder;
use Restina\App;
use Exception;

/**
 * ORM模型基类
 * @package Restina
 */
class Model extends EloquentModel
{
    /**
     * 模型字段白名单
     *
     * @var array
     */
    protected $guarded = [];

    /**
     * 模型构造函数
     *
     * @param array $attributes
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        // 设置数据库连接
        $this->setupConnection();
    }

    /**
     * 设置数据库连接
     *
     * @return void
     */
    private function setupConnection(): void
    {
        if (empty($this->connection)) {
            try {
                $app = App::init();
                if ($app->isBootstrapped()) {
                    $defaultConnection = $app->getConfig('database.default', 'mysql');
                    $this->connection = $defaultConnection;
                }
            } catch (Exception $e) {
                // 记录错误日志
                error_log("Restina Model: Database connection setup failed: " . $e->getMessage());
                // 设置默认值以防配置失败
                $this->connection = 'mysql';
            }
        }
    }

    /**
     * 创建查询构建器的新实例
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function newEloquentBuilder($query)
    {
        return new Builder($query);
    }

    /**
     * 重写父类的on方法，使其与Eloquent兼容
     *
     * @param string|null $connection
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function on($connection = null): \Illuminate\Database\Eloquent\Builder
    {
        // 调用父类的on方法以确保兼容性
        return parent::on($connection);
    }

    /**
     * 在指定的连接上运行查询
     *
     * @param string $connectionName
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function onConnection(string $connectionName): \Illuminate\Database\Eloquent\Builder
    {
        return static::on($connectionName);
    }

    /**
     * 动态设置数据库连接
     *
     * @param string $connection
     * @return $this
     */
    public function setConnectionName(string $connection): self
    {
        $this->connection = $connection;
        return $this;
    }
}
