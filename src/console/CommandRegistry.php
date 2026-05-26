<?php
// restina/Console/CommandRegistry.php

namespace Restina\Console;

use Restina\App;

/**
 * 命令注册器
 */
class CommandRegistry
{
    /**
     * 命令列表
     * @var Command[]
     */
    private array $commands = [];

    /**
     * 注册命令
     * @param string $commandClass
     */
    public function register(string $commandClass): void
    {
        if (!class_exists($commandClass)) {
            throw new \InvalidArgumentException("Command class {$commandClass} does not exist");
        }

        $command = new $commandClass();
        if (!$command instanceof Command) {
            throw new \InvalidArgumentException("Command must extend Restina\Console\Command");
        }

        $this->commands[$command->getSignature()] = $command;
    }

    /**
     * 获取所有命令
     * @return Command[]
     */
    public function getCommands(): array
    {
        return $this->commands;
    }

    /**
     * 查找命令
     * @param string $signature
     * @return Command|null
     */
    public function find(string $signature): ?Command
    {
        return $this->commands[$signature] ?? null;
    }

    /**
     * 运行命令
     * @param string $signature
     * @param App $app
     * @return int
     */
    public function run(string $signature, App $app): int
    {
        $command = $this->find($signature);
        if (!$command) {
            throw new \InvalidArgumentException("Command {$signature} not found");
        }

        return $command->handle($app);
    }
}
