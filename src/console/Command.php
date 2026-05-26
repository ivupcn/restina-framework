<?php
// restina/console/Command.php

namespace Restina\console;

use Restina\App;

/**
 * 命令基类
 * @package Restina\console
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
     * 选项
     * @var array
     */
    protected array $options = [];

    /**
     * 输入
     * @var array
     */
    protected array $inputs = [];

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
        $args = $_SERVER['argv'];

        // 查找 --name value 形式的参数
        $pos = array_search("--{$name}", $args);
        if ($pos !== false && isset($args[$pos + 1]) && !str_starts_with($args[$pos + 1], '--')) {
            return $args[$pos + 1];
        }

        // 查找 -n value 或 -nvalue 形式的短参数
        foreach ($args as $index => $arg) {
            if (preg_match('/^-(\w)(?:=(.*))?$/', $arg, $matches)) {
                $shortOption = $matches[1];
                $value = $matches[2] ?? null;

                // 如果当前短选项有值，直接返回
                if ($value !== null) {
                    // 需要建立短选项到长选项的映射
                    return $value;
                }

                // 检查下一个参数是否是值
                if (isset($args[$index + 1]) && !str_starts_with($args[$index + 1], '-')) {
                    return $args[$index + 1];
                }
            }
        }

        // 返回默认值
        return $this->inputs[$name]['default'] ?? null;
    }

    /**
     * 添加参数
     *
     * @param string $name
     * @param int $mode
     * @param string $description
     * @param mixed $default
     */
    public function addArgument(string $name, int $mode, string $description = '', $default = null)
    {
        $this->inputs[$name] = [
            'type' => 'argument',
            'mode' => $mode,
            'description' => $description,
            'default' => $default
        ];
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
     * 选项（开关型参数）
     * @param string $name
     * @return bool
     */
    protected function hasOption(string $name): bool
    {
        return in_array("--{$name}", $_SERVER['argv']);
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

    /**
     * 验证输入
     * @param array $required
     * @param array $provided
     * @return bool
     */
    protected function validateInput(array $required, array $provided): bool
    {
        foreach ($required as $input) {
            if (!isset($provided[$input])) {
                $this->error("缺少必需的参数: {$input}");
                return false;
            }
        }
        return true;
    }

    /**
     * 输出信息
     *
     * @param string $message
     */
    protected function info(string $message): void
    {
        $this->output("\033[36mINFO\033[0m: {$message}");
    }

    /**
     * 输出成功信息
     *
     * @param string $message
     */
    protected function success(string $message): void
    {
        $this->output("\033[32mSUCCESS\033[0m: {$message}");
    }

    /**
     * 输出警告信息
     *
     * @param string $message
     */
    protected function warning(string $message): void
    {
        $this->output("\033[33mWARNING\033[0m: {$message}");
    }
}
