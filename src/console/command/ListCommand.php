<?php
// restina/console/command/ListCommand.php

namespace Restina\console\command;

use Restina\App;

/**
 * 列出所有可用命令
 * @package Restina\console\command
 */
class ListCommand extends Command
{
    protected string $signature = 'list';
    protected string $description = '列出所有可用命令';

    protected function configure(): void
    {
        // 不需要额外配置
    }

    public function handle(App $app): int
    {
        $registry = $app->getCommandRegistry();
        $commands = $registry->getCommands();

        $this->output("Restina Framework Console Tool");
        $this->output("");
        $this->output("Available commands:");

        foreach ($commands as $signature => $command) {
            $this->output(sprintf("  %-20s %s", $signature, $command->getDescription()));
        }

        return 0;
    }
}
