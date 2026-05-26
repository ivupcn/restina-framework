<?php
// restina/src/Console.php

namespace Restina;

use Restina\console\CommandRegistry;
use Restina\console\command\HelpCommand;
use Restina\console\command\ListCommand;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * 命令行工具
 * @package Restina
 */
class Console
{
    /**
     * @var App
     */
    private App $app;

    /**
     * @var CommandRegistry
     */
    private CommandRegistry $commandRegistry;

    /**
     * Console 构造函数.
     * @param App $app
     */
    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * 运行 CLI 命令
     */
    public function run(): int
    {
        if (!isset($this->commandRegistry)) {
            $this->initCommandRegistry();
        }

        // 获取命令参数
        global $argv;
        $commandName = isset($argv[1]) ? $argv[1] : 'list';

        if ($commandName === 'list' || $commandName === 'help') {
            return $this->listCommands();
        }

        try {
            return $this->commandRegistry->run($commandName, $this);
        } catch (\Exception $e) {
            fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
            return 1;
        }
    }

    /**
     * 列出所有可用命令
     */
    private function listCommands(): int
    {
        $commands = $this->getCommandRegistry()->getCommands();

        echo "Restina Framework Console Tool\n";
        echo "\n";
        echo "Usage:\n";
        echo "  php restina <command> [options] [arguments]\n";
        echo "\n";
        echo "Available commands:\n";

        foreach ($commands as $signature => $command) {
            echo sprintf("  %-20s %s\n", $signature, $command->getDescription());
        }

        echo "\n";
        return 0;
    }

    /**
     * 获取命令注册器
     */
    public function getCommandRegistry(): CommandRegistry
    {
        if (!isset($this->commandRegistry)) {
            $this->initCommandRegistry();
        }
        return $this->commandRegistry;
    }

    /**
     * 初始化命令注册器
     */
    private function initCommandRegistry(): void
    {
        $this->commandRegistry = new CommandRegistry();

        // 注册内置命令
        $this->commandRegistry->register(HelpCommand::class);
        $this->commandRegistry->register(ListCommand::class);

        // 自动扫描并注册 app/commands 目录下的命令
        $this->registerCommandsFromDirectory();
    }

    /**
     * 从目录扫描并注册命令
     */
    private function registerCommandsFromDirectory(): void
    {
        $commandsDir = $this->app->getAppPath() . '/commands';

        if (!is_dir($commandsDir)) {
            return;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($commandsDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                /** @var SplFileInfo $file */
                if ($file->getExtension() === 'php') {
                    $this->registerCommandFromFile($file->getPathname());
                }
            }
        } catch (\Exception $e) {
            fwrite(STDERR, "Warning: Error scanning commands directory: " . $e->getMessage() . "\n");
        }
    }

    /**
     * 从文件中注册命令类
     */
    private function registerCommandFromFile(string $filePath): void
    {
        // 获取文件内容并提取类名
        $content = file_get_contents($filePath);

        // 提取命名空间
        if (preg_match('/namespace\s+([^\s;]+)/', $content, $nsMatches)) {
            $namespace = trim($nsMatches[1]);
        } else {
            // 如果没有命名空间，使用默认命名空间
            $namespace = 'App\\Commands';
        }

        // 提取类名
        if (preg_match('/class\s+(\w+)/', $content, $classMatches)) {
            $className = $namespace . '\\' . $classMatches[1];

            // 确保类存在且是命令类
            if (class_exists($className)) {
                $reflection = new \ReflectionClass($className);

                // 检查是否继承自 Command 基类
                if ($reflection->isSubclassOf(\Restina\Console\Command::class) && !$reflection->isAbstract()) {
                    try {
                        $this->commandRegistry->register($className);
                    } catch (\Exception $e) {
                        fwrite(STDERR, "Warning: Failed to register command {$className}: " . $e->getMessage() . "\n");
                    }
                }
            }
        }
    }

    /**
     * 检查是否在 CLI 模式下运行
     */
    public function isCliMode(): bool
    {
        return php_sapi_name() === 'cli';
    }

    /**
     * 获取应用实例
     */
    public function getApp(): App
    {
        return $this->app;
    }
}
