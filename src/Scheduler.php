<?php
// vendor/ivupcn/restina/src/Scheduler.php

namespace Restina;

/**
 * 调度器
 */
class Scheduler
{
    private array $tasks = [];
    private array $dynamicTasks = [];
    private Config $config;
    private bool $running = false;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * 加载配置文件中的计划任务
     */
    private function loadConfigFile(): void
    {
        $configFile = $this->config->get('app.base_path', '') . '/app/scheduler.php';

        if (file_exists($configFile)) {
            $configTasks = include $configFile;

            if (is_array($configTasks)) {
                foreach ($configTasks as $name => $task) {
                    if (!isset($this->tasks[$name])) {
                        $this->tasks[$name] = $task;
                    }
                }
            }
        }
    }

    /**
     * 动态添加任务
     */
    public function addTask(string $name, string $cron, callable $callback, string $description = ''): void
    {
        $this->dynamicTasks[$name] = [
            'cron' => $cron,
            'name' => $name,
            'desc' => $description,
            'callback' => $callback,
            'type' => 'callback'
        ];
    }

    /**
     * 获取所有任务
     */
    public function getAllTasks(): array
    {
        return array_merge($this->tasks, $this->dynamicTasks);
    }

    /**
     * 运行调度器
     */
    public function run(): void
    {
        if ($this->running) {
            return;
        }

        $this->running = true;

        echo "Scheduler started at " . date('Y-m-d H:i:s') . "\n";

        while (true) {
            $now = time();
            $currentMinute = date('H:i', $now);

            foreach ($this->getAllTasks() as $task) {
                $cronExpression = new Cron($task['cron']);
                if ($cronExpression->isDue()) {
                    $this->executeTask($task);
                }
            }

            sleep(60); // 每分钟检查一次
        }
    }

    /**
     * 检查并执行到期的任务
     */
    private function checkAndExecuteTasks(): void
    {
        $now = time();

        foreach ($this->getAllTasks() as $task) {
            $cronExpression = new Cron($task['cron']);

            if ($cronExpression->isDue()) {
                $this->executeTask($task, $now);
            }
        }
    }

    /**
     * 执行单个任务
     */
    private function executeTask(array $task): void
    {
        try {
            echo "Executing task: {$task['name']} at " . date('Y-m-d H:i:s') . "\n";

            if ($task['type'] === 'method') {
                $instance = new $task['class']();
                call_user_func([$instance, $task['method']]);
            } elseif ($task['type'] === 'callback') {
                call_user_func($task['callback']);
            }

            echo "Task {$task['name']} completed successfully\n";
        } catch (\Exception $e) {
            echo "Task {$task['name']} failed: " . $e->getMessage() . "\n";
        }
    }

    /**
     * 停止调度器
     */
    public function stop(): void
    {
        $this->running = false;
    }
}
