<?php

namespace Restina;

use DateTime;
use InvalidArgumentException;

class Validator
{
    /**
     * 验证参数
     * 
     * @param mixed $value 参数值
     * @param string $docComment 方法的文档注释
     * @param string $paramName 参数名
     * @return mixed 验证后的参数值
     * @throws InvalidArgumentException
     */
    public static function validate(mixed $value, string $docComment, string $paramName): mixed
    {
        $rules = self::extractRules($docComment, $paramName);

        if (empty($rules)) {
            return $value;
        }

        // 如果值为 null 且有 optional 规则，则跳过后续验证
        if ($value === null && in_array('optional', array_column($rules, 'name'))) {
            return $value;
        }

        foreach ($rules as $rule) {
            $value = self::applyRule($value, $rule, $docComment, $paramName);
        }

        return $value;
    }

    /**
     * 从文档注释中提取参数规则
     * 
     * @param string $docComment 文档注释
     * @param string $paramName 参数名
     * @return array 规则数组
     */
    private static function extractRules(string $docComment, string $paramName): array
    {
        $pattern = '/@param\s+[^\s]+\s+\$' . preg_quote($paramName, '/') . '\s+{(@v\s+[^}]+)}/i';
        preg_match_all($pattern, $docComment, $matches);

        if (empty($matches[1])) {
            return [];
        }

        $rules = [];
        foreach ($matches[1] as $match) {
            $ruleString = trim(str_replace('@v', '', $match));
            $rules = array_merge($rules, self::parseRules($ruleString));
        }

        return $rules;
    }

    /**
     * 解析规则字符串
     * 
     * @param string $ruleString 规则字符串
     * @return array 规则数组
     */
    private static function parseRules(string $ruleString): array
    {
        $rules = [];
        $parts = explode('|', $ruleString);

        foreach ($parts as $part) {
            $part = trim($part);
            if (strpos($part, ':') !== false) {
                [$ruleName, $ruleValue] = explode(':', $part, 2);
                $rules[] = [
                    'name' => trim($ruleName),
                    'value' => trim($ruleValue)
                ];
            } else {
                $rules[] = [
                    'name' => trim($part),
                    'value' => null
                ];
            }
        }

        return $rules;
    }

    /**
     * 应用单个规则
     * 
     * @param mixed $value 参数值
     * @param array $rule 规则
     * @param string $docComment 方法的文档注释
     * @param string $paramName 参数名
     * @return mixed 验证后的参数值
     * @throws InvalidArgumentException
     */
    private static function applyRule(mixed $value, array $rule, string $docComment, string $paramName): mixed
    {
        $ruleName = $rule['name'];
        $ruleValue = $rule['value'];

        switch ($ruleName) {
            case 'required':
                if ($value === null || $value === '') {
                    throw new InvalidArgumentException("参数 '{$paramName}' 是必填项");
                }
                break;

            case 'equals':
                if ($value !== $ruleValue) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 必须等于 '{$ruleValue}'");
                }
                break;

            case 'different':
                if ($value === $ruleValue) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 必须与 '{$ruleValue}' 不同");
                }
                break;

            case 'accepted':
                if (!in_array($value, [true, 1, '1', 'on', 'yes', 'true'], true)) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 必须被接受");
                }
                break;

            case 'numeric':
                if (!is_numeric($value)) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 必须是数字");
                }
                break;

            case 'integer':
                if (!filter_var($value, FILTER_VALIDATE_INT)) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 必须是整数");
                }
                break;

            case 'boolean':
                if (!is_bool($value) && !in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 必须是布尔值");
                }
                break;

            case 'array':
                if (!is_array($value)) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 必须是数组");
                }
                break;

            case 'length':
                if (!is_string($value) || strlen($value) != $ruleValue) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 长度必须为 {$ruleValue}");
                }
                break;

            case 'lengthBetween':
                if (!is_string($value)) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 必须是字符串才能进行长度验证");
                }
                [$min, $max] = explode(',', $ruleValue);
                $len = strlen($value);
                if ($len < $min || $len > $max) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 长度必须在 {$min} 和 {$max} 之间");
                }
                break;

            case 'lengthMin':
                if (!is_string($value)) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 必须是字符串才能进行长度验证");
                }
                if (strlen($value) < $ruleValue) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 长度不能少于 {$ruleValue}");
                }
                break;

            case 'lengthMax':
                if (!is_string($value)) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 必须是字符串才能进行长度验证");
                }
                if (strlen($value) > $ruleValue) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 长度不能超过 {$ruleValue}");
                }
                break;

            case 'min':
                if ($value < $ruleValue) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 不能小于 {$ruleValue}");
                }
                break;

            case 'max':
                if ($value > $ruleValue) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 不能大于 {$ruleValue}");
                }
                break;

            case 'in':
                $allowedValues = explode(',', $ruleValue);
                if (!in_array($value, $allowedValues)) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 必须是以下值之一: " . $ruleValue);
                }
                break;

            case 'notIn':
                $forbiddenValues = explode(',', $ruleValue);
                if (in_array($value, $forbiddenValues)) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 不能是以下值之一: " . $ruleValue);
                }
                break;

            case 'ip':
                if (!filter_var($value, FILTER_VALIDATE_IP)) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 必须是有效的IP地址");
                }
                break;

            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 必须是有效的邮箱地址");
                }
                break;

            case 'url':
                if (!filter_var($value, FILTER_VALIDATE_URL)) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 必须是有效的URL");
                }
                break;

            case 'urlActive':
                if (!filter_var($value, FILTER_VALIDATE_URL)) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 必须是有效的URL");
                }
                $host = parse_url($value, PHP_URL_HOST);
                if (!$host || !checkdnsrr($host)) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 必须是具有活动DNS记录的URL");
                }
                break;

            case 'alpha':
                if (!is_string($value) || !ctype_alpha($value)) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 只能包含字母字符");
                }
                break;

            case 'alphaNum':
                if (!is_string($value) || !ctype_alnum($value)) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 只能包含字母和数字字符");
                }
                break;

            case 'slug':
                if (!is_string($value) || !preg_match('/^[a-z0-9_-]+$/', $value)) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 必须是有效的URL slug格式");
                }
                break;

            case 'regex':
                if (!is_string($value) || !preg_match($ruleValue, $value)) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 不符合要求的格式");
                }
                break;

            case 'date':
                if (!DateTime::createFromFormat('Y-m-d', $value)) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 必须是有效的日期");
                }
                break;

            case 'dateFormat':
                if (!DateTime::createFromFormat($ruleValue, $value)) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 必须是 '{$ruleValue}' 格式的有效日期");
                }
                break;

            case 'dateBefore':
                $currentDate = DateTime::createFromFormat('Y-m-d', $value);
                $beforeDate = DateTime::createFromFormat('Y-m-d', $ruleValue);

                if (!$currentDate) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 必须是有效的日期");
                }
                if (!$beforeDate) {
                    throw new InvalidArgumentException("规则 'dateBefore' 中的比较日期格式无效");
                }

                if ($currentDate >= $beforeDate) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 必须早于 {$ruleValue}");
                }
                break;

            case 'dateAfter':
                $currentDate = DateTime::createFromFormat('Y-m-d', $value);
                $afterDate = DateTime::createFromFormat('Y-m-d', $ruleValue);

                if (!$currentDate) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 必须是有效的日期");
                }
                if (!$afterDate) {
                    throw new InvalidArgumentException("规则 'dateAfter' 中的比较日期格式无效");
                }

                if ($currentDate <= $afterDate) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 必须晚于 {$ruleValue}");
                }
                break;

            case 'contains':
                if (!is_string($value) || strpos($value, $ruleValue) === false) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 必须包含 '{$ruleValue}'");
                }
                break;

            case 'creditCard':
                if (!self::isValidCreditCard($value)) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 必须是有效的信用卡号");
                }
                break;

            case 'optional':
                // optional 规则仅用于标记字段可选，实际验证在前面已处理
                break;
        }

        return $value;
    }

    /**
     * 验证信用卡号码
     * 
     * @param string $number 信用卡号码
     * @return bool 是否为有效的信用卡号
     */
    private static function isValidCreditCard(string $number): bool
    {
        // 移除空格和连字符
        $number = preg_replace('/[\s-]/', '', $number);

        // 必须是数字
        if (!is_numeric($number)) {
            return false;
        }

        // Luhn 算法验证
        $sum = 0;
        $length = strlen($number);
        $alt = false;

        for ($i = $length - 1; $i >= 0; $i--) {
            $n = intval($number[$i]);

            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n = ($n % 10) + 1;
                }
            }

            $sum += $n;
            $alt = !$alt;
        }

        return ($sum % 10) == 0;
    }
}
