<?php
// restina/console/CommandRegistry.php

namespace Restina\console;

use Restina\App;

/**
 * 命令注册器
 * @package Restina\console
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
            // 提供模糊匹配提示
            $suggestions = $this->suggestSimilarCommands($signature);
            $errorMessage = "Command {$signature} not found";

            if (!empty($suggestions)) {
                $errorMessage .= ". Did you mean: " . implode(', ', $suggestions) . "?";
            }

            throw new \InvalidArgumentException($errorMessage);
        }

        return $command->handle($app);
    }

    /**
     * 建议相似的命令
     * @param string $input
     * @return array
     */
    private function suggestSimilarCommands(string $input): array
    {
        $suggestions = [];
        $commands = array_keys($this->commands);

        foreach ($commands as $command) {
            if (levenshtein($input, $command) <= 2) { // 编辑距离小于等于2
                $suggestions[] = $command;
            }
        }

        return array_slice($suggestions, 0, 3); // 最多返回3个建议
    }
}
