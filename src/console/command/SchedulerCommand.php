<?php
// restina/console/command/SchedulerCommand.php

namespace Restina\console\command;

use Restina\Scheduler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class SchedulerCommand extends Command
{
    protected static $defaultName = 'scheduler:run';

    private Scheduler $scheduler;

    public function __construct(Scheduler $scheduler)
    {
        $this->scheduler = $scheduler;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Run the scheduled tasks')
            ->addOption('scan-path', null, InputOption::VALUE_REQUIRED, 'Path to scan for scheduled classes', 'app')
            ->setHelp('This command runs all scheduled tasks based on their cron expressions.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $scanPath = $input->getOption('scan-path');
        $basePath = dirname(__DIR__, 4); // Assuming project root

        $this->scheduler->scanScheduledClasses([$basePath . '/' . $scanPath]);

        $tasks = $this->scheduler->getAllTasks();
        $output->writeln("Found " . count($tasks) . " scheduled tasks:");

        foreach ($tasks as $name => $task) {
            $output->writeln("- {$name}: {$task['cron']} - {$task['desc']}");
        }

        $output->writeln("\nStarting scheduler...");
        $this->scheduler->run();

        return Command::SUCCESS;
    }
}
