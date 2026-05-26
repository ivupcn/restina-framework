<?php
// restina/Console/Command.php

namespace Restina\Console;

use Restina\App;

/**
 * 命令基类
 * @package Restina\Console
 */
abstract class Command
{
    /**
     * 签名
     * @var string
     */
    protected string $signature;

    /**
     * 描述
     * @var string
     */
    protected string $description = '';

    /**
     * 参数
     * @var array
     */
    protected array $arguments = [];

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->configure();
    }

    /**
     * 配置
     * @return void
     */
    abstract protected function configure(): void;

    /**
     * 处理命令
     * @param App $app
     * @return int
     */
    abstract public function handle(App $app): int;

    /**
     * 获取签名
     * @return string
     */
    public function getSignature(): string
    {
        return $this->signature;
    }

    /**
     * 获取描述
     * @return string
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * 获取参数
     * @return array
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    /**
     * 参数
     * @param string $name
     * @return mixed
     */
    protected function argument(string $name): mixed
    {
        return $_SERVER['argv'][array_search("--{$name}", $_SERVER['argv']) + 1] ?? null;
    }

    /**
     * 选项
     * @param string $name
     * @return mixed
     */
    protected function option(string $name): mixed
    {
        $index = array_search("--{$name}", $_SERVER['argv']);
        if ($index !== false && isset($_SERVER['argv'][$index + 1])) {
            return $_SERVER['argv'][$index + 1];
        }
        return null;
    }

    /**
     * 输出
     * @param string $message
     * @return void
     */
    protected function output(string $message): void
    {
        echo $message . PHP_EOL;
    }

    /**
     * 错误输出
     * @param string $message
     * @return void
     */
    protected function error(string $message): void
    {
        fwrite(STDERR, $message . PHP_EOL);
    }
}
