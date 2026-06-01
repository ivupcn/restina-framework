<?php
// src/queue/command/ConsumeCommand.php

namespace Restina\queue\command;

use Restina\Console\Command;
use Restina\queue\Queue;

class ConsumeCommand extends Command
{
    protected string $signature = 'queue:consume {--queue=default : 队列名称} {--max-jobs=0 : 最大处理任务数} {--sleep=3 : 空闲时休眠秒数} {--memory=128 : 内存限制(MB)}';

    protected string $description = '消费队列中的任务';

    public function handle(Queue $queue): int
    {
        $queueName = $this->option('queue', 'default');
        $maxJobs = (int) $this->option('max-jobs', 0);
        $sleepSeconds = (int) $this->option('sleep', 3);
        $memoryLimit = (int) $this->option('memory', 128);

        $this->output("Starting queue consumer for queue: {$queueName}");
        $this->output("Max jobs: " . ($maxJobs > 0 ? $maxJobs : 'unlimited'));
        $this->output("Sleep: {$sleepSeconds}s, Memory limit: {$memoryLimit}MB");
        $this->output('');

        $jobsProcessed = 0;
        $startTime = microtime(true);

        while (true) {
            // 检查内存使用
            if (memory_get_usage(true) / 1024 / 1024 > $memoryLimit) {
                $this->output("Memory usage exceeded limit. Exiting...");
                break;
            }

            $job = $queue->pop($queueName);

            if ($job) {
                $this->output("Processing job: " . $job->job->getJobId());

                $job->markAsAttempted();

                $result = $job->handle();

                if ($result) {
                    // 成功处理
                    if ($queue->getDriverType() === 'redis') {
                        $queue->getDriver()->markCompleted($job, $queueName);
                    } else {
                        $queue->getDriver()->markCompleted($job, $queueName);
                    }

                    $this->output("Job completed successfully");
                } else {
                    // 处理失败
                    if ($job->hasExceededMaxAttempts()) {
                        $this->output("Job failed and exceeded max attempts, moving to failed queue");

                        if ($queue->getDriverType() === 'redis') {
                            $queue->getDriver()->markFailed($job, $queueName);
                        } else {
                            $queue->getDriver()->markFailed($job, $queueName);
                        }
                    } else {
                        $this->output("Job failed, will retry (attempt {$job->attempts}/{$job->maxAttempts})");

                        // 重新推送到队列
                        $queue->push($job->job, $queueName);
                    }
                }

                $jobsProcessed++;

                if ($maxJobs > 0 && $jobsProcessed >= $maxJobs) {
                    $this->output("Reached maximum jobs limit ({$maxJobs}). Exiting...");
                    break;
                }
            } else {
                // 队列为空，休眠一段时间
                sleep($sleepSeconds);
            }

            // 检查是否应该退出
            if (connection_aborted() && php_sapi_name() !== 'cli') {
                break;
            }
        }

        $duration = round(microtime(true) - $startTime, 2);
        $this->output('');
        $this->output("Consumer finished. Processed {$jobsProcessed} jobs in {$duration}s");

        return 0;
    }
}
