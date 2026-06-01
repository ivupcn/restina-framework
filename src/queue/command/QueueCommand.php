<?php
// src/queue/command/QueueCommand.php

namespace Restina\queue\command;

use Restina\Console\Command;
use Restina\queue\Queue;

/**
 * 队列工作进程命令
 */
class QueueCommand extends Command
{
    protected string $signature = 'queue:work {--queue=default : 队列名称} {--workers=1 : 工作进程数} {--daemon : 守护进程模式}';

    protected string $description = '启动队列工作进程';

    public function handle(Queue $queue): int
    {
        $queueName = $this->option('queue', 'default');
        $workersCount = (int) $this->option('workers', 1);
        $isDaemon = $this->option('daemon', false);

        $this->output("Starting queue workers for queue: {$queueName}");
        $this->output("Workers count: {$workersCount}");
        $this->output("Daemon mode: " . ($isDaemon ? 'enabled' : 'disabled'));
        $this->output('');

        if ($isDaemon) {
            $this->startDaemon($queueName, $workersCount);
        } else {
            $this->startSingleWorker($queueName);
        }

        return 0;
    }

    private function startSingleWorker(string $queueName): void
    {
        $this->call('queue:consume', [
            '--queue' => $queueName,
            '--max-jobs' => 1000,
            '--memory' => 256
        ]);
    }

    private function startDaemon(string $queueName, int $workersCount): void
    {
        $this->output("Starting daemon mode with {$workersCount} workers...");

        // 在生产环境中，你可能需要使用进程管理工具如 Supervisor
        // 这里只是一个简化的实现
        for ($i = 0; $i < $workersCount; $i++) {
            $pid = pcntl_fork();

            if ($pid == -1) {
                die('Could not fork process');
            } elseif ($pid == 0) {
                // 子进程
                $this->call('queue:consume', [
                    '--queue' => $queueName,
                    '--memory' => 256
                ]);

                exit(0);
            }
        }

        // 父进程等待子进程结束
        while (pcntl_waitpid(0, $status) != -1) {
            $status = pcntl_wexitstatus($status);
            $this->output("Worker exited with status: {$status}");
        }
    }
}
