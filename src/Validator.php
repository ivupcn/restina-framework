<?php

namespace Restina;

use DateTime;
use InvalidArgumentException;

/**
 * 验证器类
 * @package Restina
 */
class Validator
{
    /**
     * 预定义的安全正则表达式白名单
     * 
     * @var array
     */
    private static array $whitelistedPatterns = [
        // 常用验证模式
        'email' => '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', // 邮箱
        'phone' => '/^1[3-9]\d{9}$/', // 手机
        'mobile' => '/^1[3-9]\d{9}$/', // 手机
        'chinese_phone' => '/^1[3-9]\d{9}$|^0\d{2,3}-?\d{7,8}$/', // 中国大陆手机号或座机
        'telephone' => '/^0\d{2,3}-?\d{7,8}$/', // 座机
        'zipcode' => '/^\d{6}$/', // 邮编
        'id_card' => '/^[\dXx]{18}$|^\d{15}$/', // 身份证号码
        'passport' => '/^[a-zA-Z]\d{8}$|^[a-zA-Z]{2}\d{7}$/', // 护照号码
        'ip_v4' => '/^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/', // IPv4
        'ip_v6' => '/^([0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}$|^::1$|^::$/', // IPv6
        'url' => '/^https?:\/\/(?:[-\w.])+(?:\:[0-9]+)?(?:\/(?:[\w\/_.])*(?:\?(?:[\w&=%.])*)?(?:\#(?:[\w.])*)?)?$/', // URL
        'domain' => '/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/', // 域名
        'username' => '/^[a-zA-Z][a-zA-Z0-9_]{2,19}$/', // 用户名（以字母开头，长度3-20，允许字母、数字和下划线）
        'password' => '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)[a-zA-Z\d@$!%*?&]{8,}$/', // 密码（至少8个字符，至少一个大写字母、一个小写字母和一个数字）
        'hex_color' => '/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', // 十六进制颜色
        'credit_card' => '/^(?:4[0-9]{12}(?:[0-9]{3})?|5[1-5][0-9]{14}|3[47][0-9]{13}|3[0-9]{13}|6(?:011|5[0-9]{2})[0-9]{12})$/', // 信用卡号码
        'isbn' => '/^(?:ISBN[-\s]?)?(?:978|979)?[\s-]?[0-9]{1,5}[\s-]?[0-9]+[\s-]?[0-9]+[\s-]?[\dXx]$/', // 国际标准书号
        'date_iso' => '/^\d{4}-\d{2}-\d{2}$/', // ISO 8601 日期格式
        'datetime_iso' => '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{3})?(?:Z|[+-]\d{2}:\d{2})?$/', // ISO 8601 日期时间格式
        'time' => '/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', // 时间格式
        'mac_address' => '/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', // MAC地址
        'ssn' => '/^\d{3}-\d{2}-\d{4}$/', // 美国社会安全号码
        'vin' => '/^[A-HJ-NPR-Z0-9]{17}$/', // 美国车辆识别码
        'uuid' => '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', // UUID
        'base64' => '/^[A-Za-z0-9+\/]*={0,2}$/', // Base64编码
        'md5' => '/^[a-fA-F0-9]{32}$/', // MD5
        'sha1' => '/^[a-fA-F0-9]{40}$/', // SHA1
        'sha256' => '/^[a-fA-F0-9]{64}$/', // SHA256
        'latitude' => '/^(-?[1-8]?\d(?:\.\d{1,18})?|90(?:\.0{1,18})?)$/', // 纬度
        'longitude' => '/^(-?(?:1[0-7]|[1-9])?\d(?:\.\d{1,18})?|180(?:\.0{1,18})?)$/', // 经度
        'currency' => '/^[\$€£¥₹₽]\d+(\.\d{2})?$/', // 货币
        'alphanumeric' => '/^[a-zA-Z0-9]+$/', // 纯字母数字
        'alpha' => '/^[a-zA-Z]+$/', // 纯字母
        'numeric' => '/^[0-9]+$/', // 纯数字
        'slug' => '/^[a-z0-9-]+$/', // 斜线分隔的URL友好字符串
        'camel_case' => '/^[a-z]+(?:[A-Z][a-z]*)*$/', // 驼峰命名
        'pascal_case' => '/^[A-Z][a-z]*(?:[A-Z][a-z]*)*$/', // 帕斯卡命名
        'snake_case' => '/^[a-z][a-z0-9_]*$/', // 蛇形命名
        'kebab_case' => '/^[a-z][a-z0-9-]*$/', // 烤肉命名
        'credit_card_luhn' => '/^(?:4[0-9]{12}(?:[0-9]{3})?|5[1-5][0-9]{14}|3[47][0-9]{13}|3[0-9]{13}|6(?:011|5[0-9]{2})[0-9]{12})$/', // 信用卡号码（Luhn算法验证）
    ];

    /**
     * 验证参数
     * 
     * @param mixed $value 参数值
     * @param string $params 参数注释
     * @param string $paramName 参数名
     * @return mixed 验证后的参数值
     * @throws InvalidArgumentException
     */
    public static function validate(mixed $value, string $params, string $paramName): mixed
    {
        $rules = self::extractRule($params);

        if (empty($rules)) {
            return $value;
        }

        // 如果值为 null 且有 optional 规则，则跳过后续验证
        if (in_array('optional', array_column($rules, 'name')) && ($value === null || $value === '')) {
            return $value;
        }

        foreach ($rules as $rule) {
            $value = self::applyRule($value, $rule, $paramName);
        }

        return $value;
    }

    /**
     * 解析规则字符串
     * 
     * @param string $ruleString 规则字符串
     * @return array 规则数组
     */
    public static function extractRule(string $ruleString): array
    {
        $rules = [];
        $parts = explode('|', $ruleString);

        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) continue; // 跳过空部分

            if (strpos($part, ':') !== false) {
                // 只分割第一个冒号，允许值中包含冒号(如日期格式)
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
     * @param string $paramName 参数名
     * @return mixed 验证后的参数值
     * @throws InvalidArgumentException
     */
    private static function applyRule(mixed $value, array $rule, string $paramName): mixed
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
                if (!is_bool($value)) {
                    // 尝试转换字符串和数字
                    $trueValues = ['1', 'true', 'on', 'yes', true];
                    $falseValues = ['0', 'false', 'off', 'no', false];
                    if (in_array($value, $trueValues, true)) {
                        $value = true;
                    } elseif (in_array($value, $falseValues, true)) {
                        $value = false;
                    } else {
                        throw new InvalidArgumentException("参数 '{$paramName}' 必须是布尔值");
                    }
                }
                break;

            case 'array':
                if (!is_array($value)) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 必须是数组");
                }
                break;

            case 'length':
                if (!is_string($value) && !is_numeric($value)) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 必须是字符串或数字才能验证长度");
                }
                $len = strlen((string)$value);
                if ($len != $ruleValue) {
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
                // 添加输入长度限制以防止ReDoS攻击
                if (strlen($value) > 10000) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 长度超过最大限制 10000 字符");
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
                if (!$host) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 的URL缺少主机名");
                }
                // 注意：checkdnsrr 可能阻塞，生产环境建议异步或缓存
                if (!checkdnsrr($host)) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 的域名无法解析");
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
                if (!is_string($value)) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 必须是字符串才能进行正则验证");
                }

                // 验证输入长度以防止ReDoS攻击
                if (strlen($value) > 10000) {
                    throw new InvalidArgumentException("参数 '{$paramName}' 长度超过最大限制 10000 字符");
                }

                // 安全的正则表达式验证
                $result = self::safeRegexMatch($ruleValue, $value, $paramName);
                if (!$result) {
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
     * 安全的正则表达式匹配，防止正则表达式注入和ReDoS攻击
     * 
     * @param string $pattern 正则表达式模式
     * @param string $value 待验证的值
     * @param string $paramName 参数名
     * @return bool 验证结果
     * @throws InvalidArgumentException
     */
    private static function safeRegexMatch(string $pattern, string $value, string $paramName): bool
    {
        // 验证输入长度以防止ReDoS攻击
        if (strlen($pattern) > 1000) {
            throw new InvalidArgumentException("正则表达式模式过于复杂");
        }

        // 检查是否是预定义的安全模式
        if (isset(self::$whitelistedPatterns[$pattern])) {
            $pattern = self::$whitelistedPatterns[$pattern];
        } else {
            // 验证正则表达式的安全性
            if (!self::isSafeRegexPattern($pattern)) {
                throw new InvalidArgumentException("正则表达式模式包含不安全的模式");
            }
        }

        // 设置执行超时以防止长时间运行
        $start = microtime(true);

        // 执行正则匹配
        $result = @preg_match($pattern, $value, $matches, PREG_OFFSET_CAPTURE, 0);

        // 检查执行时间
        $executionTime = microtime(true) - $start;
        if ($executionTime > 1.0) { // 超过1秒则视为超时
            throw new InvalidArgumentException("正则表达式执行超时");
        }

        // 检查preg_match是否发生错误
        if ($result === false) {
            throw new InvalidArgumentException("正则表达式模式无效: " . $pattern);
        }

        return $result === 1;
    }

    /**
     * 检查正则表达式模式是否安全，防止危险模式
     * 
     * @param string $pattern 正则表达式模式
     * @return bool 是否安全
     */
    private static function isSafeRegexPattern(string $pattern): bool
    {
        // 检查是否存在潜在的危险模式
        $dangerousPatterns = [
            '/(.+)\1/', // 捕获组递归
            '/\(\?R\)/', // 递归模式
            '/\(\?<\w+>\)/', // 命名捕获组递归
            '/\{\d*,\d*\}/', // 量词可能导致指数级复杂度
            '/\(\?:.+\)\{\d*,\d*\}/', // 非捕获组量词
            '/\(\?.+\)\{\d*,\d*\}/', // 条件子模式量词
        ];

        foreach ($dangerousPatterns as $dangerousPattern) {
            if (preg_match($dangerousPattern, $pattern)) {
                return false;
            }
        }

        // 检查模式复杂度（简单估算）
        $complexity = 0;
        $tokens = [
            '/\*/', // 任意次数
            '/\+/', // 一次或多次
            '/\?/', // 零次或一次
            '/\{\d*,\d*\}/', // 量词
            '/\[.*\]/', // 字符类
            '/\|/', // 交替
            '/\(.*\)/', // 捕获组
        ];

        foreach ($tokens as $token) {
            $count = preg_match_all($token, $pattern);
            $complexity += $count;
        }

        // 如果复杂度过高，认为不安全
        if ($complexity > 20) {
            return false;
        }

        return true;
    }

    /**
     * 验证信用卡号码
     * 
     * @param string $number 信用卡号码
     * @return bool 是否为有效的信用卡号
     */
    private static function isValidCreditCard(string $number): bool
    {
        // 修复：移除所有非数字字符，而不仅仅是空格和横杠
        $number = preg_replace('/\D/', '', $number);

        // 必须是数字且长度合理
        if (!is_numeric($number) || strlen($number) < 13 || strlen($number) > 19) {
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
