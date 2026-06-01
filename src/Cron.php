<?php
// vendor/ivupcn/restina/src/Cron.php

namespace Restina;

class Cron
{
    private string $expression;
    private array $parts;

    public function __construct(string $expression)
    {
        $this->expression = trim($expression);
        $this->parse();
    }

    private function parse(): void
    {
        // 预处理特殊符号（@aliases）
        $this->expression = $this->expandAliases($this->expression);

        $parts = preg_split('/\s+/', $this->expression);

        if (count($parts) !== 5) {
            throw new \InvalidArgumentException("Invalid cron expression: {$this->expression}");
        }

        $this->parts = $parts;
    }

    private function expandAliases(string $expression): string
    {
        $aliases = [
            '@yearly' => '0 0 1 1 *',
            '@annually' => '0 0 1 1 *',
            '@monthly' => '0 0 1 * *',
            '@weekly' => '0 0 * * 0',
            '@daily' => '0 0 * * *',
            '@midnight' => '0 0 * * *',
            '@hourly' => '0 * * * *'
        ];

        $normalizedExpression = trim($expression);

        // 检查是否是别名表达式
        if (isset($aliases[$normalizedExpression])) {
            return $aliases[$normalizedExpression];
        }

        return $expression;
    }

    public function isDue(): bool
    {
        $now = time();

        return $this->matchesMinute(date('i', $now)) &&
            $this->matchesHour(date('H', $now)) &&
            $this->matchesDay(date('d', $now)) &&
            $this->matchesMonth(date('m', $now)) &&
            $this->matchesWeekday(date('w', $now));
    }

    private function matchesMinute(string $minute): bool
    {
        return $this->matchPart($this->parts[0], $minute, 0, 59);
    }

    private function matchesHour(string $hour): bool
    {
        return $this->matchPart($this->parts[1], $hour, 0, 23);
    }

    private function matchesDay(string $day): bool
    {
        return $this->matchPart($this->parts[2], $day, 1, 31);
    }

    private function matchesMonth(string $month): bool
    {
        return $this->matchPart($this->parts[3], $month, 1, 12);
    }

    private function matchesWeekday(string $weekday): bool
    {
        return $this->matchPart($this->parts[4], $weekday, 0, 6);
    }

    private function matchPart(string $pattern, string $value, int $min, int $max): bool
    {
        if ($pattern === '*') {
            return true;
        }

        if ($pattern === $value) {
            return true;
        }

        if (strpos($pattern, ',') !== false) {
            $values = explode(',', $pattern);
            return in_array($value, $values);
        }

        // 处理步长格式: */n 或 a-b/n
        if (strpos($pattern, '/') !== false) {
            [$range, $step] = explode('/', $pattern, 2);
            $step = max(1, intval($step)); // 步长至少为 1

            // 情况 1: 全局范围 */n (等同于 min-max/n)
            if ($range === '*') {
                $start = $min;
                $end = $max;
            }
            // 情况 Shift: 范围 a-b/n
            elseif (preg_match('/^(\d+)-(\d+)$/', $range, $matches)) {
                $start = max($min, intval($matches[1]));
                $end = min($max, intval($matches[2]));
            }
            // 情况 3: 无效格式，降级处理
            else {
                // 如果格式不匹配，尝试直接比较 (如 "0/5" 这种写法)
                if (is_numeric($range)) {
                    return $value === intval($range);
                }
                return false;
            }

            // 【核心修复】判断逻辑：
            // 1. 值必须在范围内
            // 2. (值 - 起始值) 必须能被步长整除
            if ($value >= $start && $value <= $end) {
                return ($value - $start) % $step === 0;
            }

            return false;
        }

        if (preg_match('/^(\d+)-(\d+)$/', $pattern, $matches)) {
            $start = intval($matches[1]);
            $end = intval($matches[2]);
            $current = intval($value);
            return $current >= $start && $current <= $end;
        }

        return $pattern === $value;
    }
}
