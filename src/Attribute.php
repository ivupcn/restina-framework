<?php
// restina/Attribute.php

namespace Restina;

use ReflectionClass;
use ReflectionMethod;
use Restina\attribute\Docs;
use Restina\attribute\Route;
use Restina\attribute\Api;
use Restina\attribute\Headers;
use Restina\attribute\Params;
use Restina\attribute\Returns;

/**
 * 属性注解类，负责扫描控制器并生成API文档数组
 * @package Restina
 */
class Attribute
{
    /**
     * 控制器目录
     */
    protected string $dir;

    /**
     * 构造函数
     *
     * @param string $dir 控制器目录，默认为 app/controllers
     */
    public function __construct(string $dir = '')
    {
        $this->dir = $dir ? $dir : dirname(__DIR__, 4) . '/app/controllers';
    }

    /**
     * 生成API文档数组
     *
     * @param array|null $controllerClasses
     * @return array
     */
    public function generate(?array $controllerClasses = null): array
    {
        // 如果没有传入控制器类，自动扫描
        if ($controllerClasses === null) {
            $controllerClasses = $this->getAllControllerClasses();
        }
        $documentation = [];
        foreach ($controllerClasses as $controllerClass) {
            // 获取控制器类的反射对象，并提取类级别的文档信息
            $reflectionClass = new ReflectionClass($controllerClass);
            $controllerAttribute = $reflectionClass->getAttributes(Docs::class)[0] ?? null;
            $controllerDocs = [];
            if ($controllerAttribute) {
                $controllerInstance = $controllerAttribute->newInstance();
                $controllerDocs = [
                    'class' => $controllerClass,
                    'title' => $controllerInstance->title,
                    'description' => $controllerInstance->description,
                    'category' => $controllerInstance->category,
                    'endpoints' => []
                ];
            }
            // 获取控制器属性中的公共方法，并提取每个方法的端点信息
            $methods = $reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC);
            foreach ($methods as $method) {
                $endpointInfo = $this->extractEndpointInfo($method);
                if ($endpointInfo) {
                    $controllerDocs['endpoints'][] = $endpointInfo;
                }
            }
            if (!empty($controllerDocs)) {
                $documentation[] = $controllerDocs;
            }
        }
        return $documentation;
    }

    /**
     * 获取所有路由
     *
     * @param array|null $controllerClasses
     * @return array
     */
    public function getRouteCollector(?array $controllerClasses = null): array
    {
        // 如果没有传入控制器类，自动扫描
        if ($controllerClasses === null) {
            $controllerClasses = $this->getAllControllerClasses();
        }
        $routes = [];
        foreach ($controllerClasses as $class) {
            $reflection = new \ReflectionClass($class);
            $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
            foreach ($methods as $method) {
                $routeInfo = $this->extractRoute($method);
                // 检查路由信息是否存在，如果不存在则跳过
                if (!$routeInfo) {
                    continue;
                }
                $paramInfo = $this->extractParams($method);
                $headersInfo = $this->extractHeaders($method);
                if ($routeInfo) {
                    $routeInfo['params'] = $paramInfo;
                    $routeInfo['headers'] = $headersInfo;
                    $routeInfo['class'] = $class;
                    $routes[] = $routeInfo;
                }
            }
        }
        return $routes;
    }

    /**
     * 获取所有控制器类
     *
     * @return array
     */
    public function getAllControllerClasses(): array
    {
        $classes = [];
        if (!is_dir($this->dir)) {
            return $classes;
        }
        try {
            // 递归扫描目录获取所有PHP文件，并提取类名
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dir)
            );
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $content = file_get_contents($file->getPathname());
                    $parsed = self::extractNamespaceAndClass($content);
                    if ($parsed !== null) {
                        $className = $parsed[0] . '\\' . $parsed[1];
                        if (class_exists($className)) {
                            $classes[] = $className;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            error_log("目录扫描错误: " . $e->getMessage());
        }
        return $classes;
    }

    /**
     * 使用 PHP tokenizer 从文件内容中提取命名空间和类名
     * 相比正则解析，能正确跳过注释和字符串中的 namespace/class 关键字
     *
     * @param string $content PHP 文件内容
     * @return array|null [namespace, className] 或 null
     */
    private static function extractNamespaceAndClass(string $content): ?array
    {
        $tokens = token_get_all($content);
        $namespace = null;
        $className = null;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            // 跳过非数组 token（标点符号等）
            if (!is_array($token)) {
                continue;
            }

            // 提取命名空间
            if ($token[0] === T_NAMESPACE) {
                $nsParts = [];
                // 向后扫描收集命名空间各部分（T_NAME_QUALIFIED 或 T_STRING + T_NS_SEPARATOR）
                for ($j = $i + 1; $j < $count; $j++) {
                    $next = $tokens[$j];
                    if (is_array($next)) {
                        if ($next[0] === T_NAME_QUALIFIED || $next[0] === T_STRING) {
                            $nsParts[] = $next[1];
                        } elseif ($next[0] === T_NS_SEPARATOR) {
                            continue;
                        } elseif ($next[0] === T_WHITESPACE) {
                            continue;
                        } else {
                            break;
                        }
                    } else {
                        // 遇到分号或花括号结束
                        break;
                    }
                }
                if (!empty($nsParts)) {
                    $namespace = implode('\\', $nsParts);
                }
            }

            // 提取类名（跳过 T_DOUBLE_COLONS 后的 class，如 Foo::class）
            if ($token[0] === T_CLASS) {
                // 检查前一个有效 token 是否是 ::（T_DOUBLE_COLON），如果是则跳过
                $prevToken = null;
                for ($k = $i - 1; $k >= 0; $k--) {
                    if (is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                        continue;
                    }
                    $prevToken = $tokens[$k];
                    break;
                }
                if (is_array($prevToken) && $prevToken[0] === T_DOUBLE_COLON) {
                    continue;
                }

                // 向后扫描找到类名
                for ($j = $i + 1; $j < $count; $j++) {
                    $next = $tokens[$j];
                    if (is_array($next)) {
                        if ($next[0] === T_WHITESPACE) {
                            continue;
                        }
                        if ($next[0] === T_STRING) {
                            $className = $next[1];
                            break;
                        }
                    }
                    break;
                }

                // 找到类名后提前返回
                if ($className !== null && $namespace !== null) {
                    return [$namespace, $className];
                }
            }
        }

        return ($namespace !== null && $className !== null) ? [$namespace, $className] : null;
    }

    /**
     * 提取端点信息
     *
     * @param ReflectionMethod $method
     * @return array|null
     */
    private function extractEndpointInfo(ReflectionMethod $method): ?array
    {
        $routeInfo = $this->extractRoute($method);
        $apiInfo = $this->extractApi($method);
        // 检查路由信息和API信息是否存在，如果不存在则返回null
        if (!$routeInfo || !$apiInfo) {
            return null;
        }
        $paramInfo = $this->extractParams($method, $routeInfo['path']);
        $headersInfo = $this->extractHeaders($method);
        $returnsInfo = $this->extractReturns($method);
        return [
            'route' => $routeInfo,
            'api' => $apiInfo,
            'params' => $paramInfo,
            'headers' => $headersInfo,
            'returns' => $returnsInfo
        ];
    }

    /**
     * 获取路由信息
     *
     * @param ReflectionMethod $method
     * @return array|null
     */
    private function extractRoute(ReflectionMethod $method): ?array
    {
        // 获取路由注解信息
        $attribute = $method->getAttributes(Route::class)[0] ?? null;
        if (!$attribute) {
            return null;
        }
        $instance = $attribute->newInstance();
        return [
            'httpMethods' => $instance->getMethodsAsArray(),
            'methodName' => $method->getName(),
            'path' => $instance->path,
            'code' => $instance->code,
            'permission' => $instance->permission,
            'jwt' => $instance->jwt,
            'autoRefreshToken' => $instance->autoRefreshToken,
        ];
    }

    /**
     * 提取API信息
     *
     * @param ReflectionMethod $method
     * @return array|null
     */
    private function extractApi(ReflectionMethod $method): ?array
    {
        // 获取API注解信息
        $attribute = $method->getAttributes(Api::class)[0] ?? null;
        if (!$attribute) {
            return null;
        }
        $instance = $attribute->newInstance();
        return [
            'title' => $instance->title,
            'description' => $instance->description,
            'responseMime' => $instance->responseMime,
            'responseExample' => $instance->responseExample,
            'payloadType' => $instance->payloadType,
            'tags' => $instance->tags,
        ];
    }

    /**
     * 获取方法参数信息
     *
     * @param ReflectionMethod $method
     * @param string $route
     * @return array
     */
    private function extractParams(ReflectionMethod $method): array
    {
        $params = [];
        // 获取参数注解信息
        $attributes = $method->getAttributes(Params::class);
        foreach ($attributes as $attribute) {
            $instance = $attribute->newInstance();
            $paramRules = $instance->rules;
            $parameter = [
                'field' => $instance->field,
                'title' => $instance->title,
                'type' => $instance->type,
                'default' => $instance->default,
                'rules' => $paramRules,
                'required' => false,
                'description' => '',
            ];
            if ($paramRules) {
                // 解析验证规则并应用到参数信息中
                $rules = $this->parserParamRules($paramRules);
                if (!empty($rules)) {
                    $parameter = $this->applyValidationRules($parameter, $rules);
                }
            }
            $params[] = $parameter;
        }
        return $params;
    }

    /**
     * 获取方法头信息
     *
     * @param ReflectionMethod $method
     * @return array
     */
    private function extractHeaders(ReflectionMethod $method): array
    {
        $headers = [];
        // 获取方法头信息
        $attributes = $method->getAttributes(Headers::class);
        foreach ($attributes as $attribute) {
            $instance = $attribute->newInstance();
            $headers[] = [
                'field' => $instance->field,
                'title' => $instance->title,
                'type' => $instance->type,
                'required' => $instance->required
            ];
        }
        return $headers;
    }

    /**
     * 获取方法返回值信息
     *
     * @param ReflectionMethod $method
     * @return array
     */
    private function extractReturns(ReflectionMethod $method): array
    {
        $returns = [];
        // 获取方法返回值信息
        $attributes = $method->getAttributes(Returns::class);
        foreach ($attributes as $attribute) {
            $instance = $attribute->newInstance();
            $returns[] = [
                'field' => $instance->field,
                'title' => $instance->title,
                'type' => $instance->type,
                'dynamic' => $instance->dynamic,
                'dynamicDescription' => $instance->dynamicDescription,
                'children' => $instance->children
            ];
        }
        return $returns;
    }

    /**
     * 解析参数验证规则
     *
     * @param string $rules
     * @return array
     */
    private function parserParamRules(string $rules): array
    {
        $rulePairs = explode('|', $rules);
        $validationRules = [];
        foreach ($rulePairs as $pair) {
            $pair = trim($pair);
            if (strpos($pair, ':') !== false) {
                [$rule, $value] = explode(':', $pair, 2);
                $validationRules[trim($rule)] = trim($value);
            } else {
                $validationRules[trim($pair)] = true;
            }
        }
        return $validationRules;
    }

    /**
     * 应用验证规则
     *
     * @param array $parameter 参数
     * @param array $validationRules 验证规则
     * @return array
     */
    private function applyValidationRules(array $parameter, array $validationRules): array
    {
        foreach ($validationRules as $rule => $value) {
            switch ($rule) {
                case 'required':
                    $parameter['required'] = true;
                    break;
                case 'optional':
                    $parameter['required'] = false;
                    break;
                case 'equals':
                    // 等于某个值
                    $parameter['enum'] = [$value];
                    break;
                case 'different':
                    // 不同于某个值
                    $parameter['description'] .= ' (不能等于 ' . $value . ')';
                    break;
                case 'accepted':
                    // 布尔值接受规则
                    $parameter['type'] = 'boolean';
                    break;
                case 'numeric':
                    // 数字验证
                    if ($parameter['type'] !== 'number' && $parameter['type'] !== 'integer') {
                        $parameter['type'] = 'number';
                    }
                    break;
                case 'integer':
                    // 整数验证
                    $parameter['type'] = 'integer';
                    break;
                case 'boolean':
                    // 布尔值验证
                    $parameter['type'] = 'boolean';
                    break;
                case 'array':
                    // 数组验证
                    $parameter['type'] = 'array';
                    break;
                case 'length':
                    // 固定长度
                    $parameter['minLength'] = (int) $value;
                    $parameter['maxLength'] = (int) $value;
                    break;
                case 'lengthBetween':
                    // 长度范围
                    $range = explode(',', $value);
                    if (count($range) === 2) {
                        $parameter['minLength'] = (int) $range[0];
                        $parameter['maxLength'] = (int) $range[1];
                    }
                    break;
                case 'lengthMin':
                    // 最小长度
                    $parameter['minLength'] = (int) $value;
                    break;
                case 'lengthMax':
                    // 最大长度
                    $parameter['maxLength'] = (int) $value;
                    break;
                case 'min':
                    // 最小值
                    $parameter['minimum'] = (int) $value;
                    break;
                case 'max':
                    // 最大值
                    $parameter['maximum'] = (int) $value;
                    break;
                case 'in':
                    // 允许的值
                    $values = explode(',', $value);
                    $parameter['enum'] = array_map('trim', $values);
                    break;
                case 'notIn':
                    // 禁止的值（Swagger不直接支持）
                    $parameter['description'] .= ' (不能是: ' . $value . ')';
                    break;
                case 'ip':
                    // IP地址格式
                    $parameter['format'] = 'ipv4';
                    break;
                case 'email':
                    // 邮箱格式
                    $parameter['format'] = 'email';
                    break;
                case 'url':
                    // URL格式
                    $parameter['format'] = 'uri';
                    break;
                case 'urlActive':
                    // 活跃URL（增强的URL验证）
                    $parameter['format'] = 'uri';
                    break;
                case 'alpha':
                    // 字母验证
                    $parameter['pattern'] = '^[a-zA-Z]+$';
                    break;
                case 'alphaNum':
                    // 字母数字验证
                    $parameter['pattern'] = '^[a-zA-Z0-9]+$';
                    break;
                case 'slug':
                    // URL友好格式
                    $parameter['pattern'] = '^[a-z0-9_-]+$';
                    break;
                case 'regex':
                    // 正则表达式
                    $parameter['pattern'] = $value;
                    break;
                case 'date':
                    // 日期格式
                    $parameter['format'] = 'date';
                    break;
                case 'dateFormat':
                    // 指定日期格式（Swagger不直接支持，但可用于文档说明）
                    $parameter['description'] .= ' (日期格式: ' . $value . ')';
                    $parameter['format'] = 'date';
                    break;
                case 'dateBefore':
                    // 日期必须早于某日期（Swagger不直接支持）
                    $parameter['description'] .= ' (必须早于 ' . $value . ')';
                    break;
                case 'dateAfter':
                    // 日期必须晚于某日期（Swagger不直接支持）
                    $parameter['description'] .= ' (必须晚于 ' . $value . ')';
                    break;
                case 'contains':
                    // 包含指定字符串（Swagger不直接支持）
                    $parameter['description'] .= ' (必须包含 ' . $value . ')';
                    break;
                case 'creditCard':
                    // 信用卡格式（Swagger不直接支持，但可以用正则）
                    $parameter['pattern'] = '^[0-9 ]+$';
                    break;
            }
        }
        return $parameter;
    }
}
