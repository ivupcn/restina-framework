<?php
// app/scheduler.php

return [
    'daily-backup' => [
        'cron' => '0 2 * * *',
        'name' => 'daily-backup',
        'desc' => '每日备份',
        'class' => App\Tasks\BackupTask::class,
        'method' => 'backup',
        'type' => 'method'
    ],
    'weekly-report' => [
        'cron' => '0 0 * * 0',
        'name' => 'weekly-report',
        'desc' => '每周报告',
        'class' => App\Tasks\ReportTask::class,
        'method' => 'generateWeeklyReport',
        'type' => 'method'
    ]
];
