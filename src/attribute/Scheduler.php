<?php
// restina/attribute/Scheduler.php

namespace Restina\attribute;

#[\Attribute(\Attribute::TARGET_METHOD)]
class Scheduler
{
    public function __construct(
        public string $cron,
        public string $name,
        public string $desc = ''
    ) {}
}
