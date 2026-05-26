<?php
// restina/console/command/HelpCommand.php

namespace Restina\console\command;

/**
 * 帮助命令
 * @package Restina\console\command
 */
class HelpCommand extends Command
{
    /**
     * 签名
     * @var string
     */
    protected string $signature = 'help';

    /**
     * 描述
     * @var string
     */
    protected string $description = '显示帮助信息';

    /**
     * 配置
     * @return void
     */
    protected function configure(): void
    {
        $this->signature = 'help {command? : 要查看帮助的命令}';
    }

    /**
     * 处理命令
     * @param App $app
     * @return int
     */
    public function handle(App $app): int
    {
        $targetCommand = $this->argument('command');

        if ($targetCommand) {
            // 查找命令注册器中的命令
            $registry = $app->resolve(CommandRegistry::class) ?? new CommandRegistry();
            $command = $registry->find($targetCommand);

            if (!$command) {
                $this->error("Command '{$targetCommand}' not found.");
                return 1;
            }

            $this->output("Description:");
            $this->output("  " . $command->getDescription());
            $this->output("");
            $this->output("Usage:");
            $this->output("  php restina {$command->getSignature()}");
        } else {
            $this->showAllCommands();
        }

        return 0;
    }

    private function showAllCommands(): void
    {
        $this->output("Restina Framework Console Tool");
        $this->output("");
        $this->output("Usage:");
        $this->output("  php restina <command> [options] [arguments]");
        $this->output("");
        $this->output("Available commands:");

        // 这里需要从命令注册器获取所有命令
        // 可能需要传递注册器实例给命令
    }
}
