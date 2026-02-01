<?php
// restina/Document.php

namespace Restina;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

class Document
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'title' => 'API 文档',
            'description' => '根据控制器注释生成的API文档',
            'version' => '1.0.0',
            'basePath' => '/',
            'schemes' => ['http'],
            'host' => 'localhost:8080'
        ], $config);
    }

    public function generate(?array $controllerClasses = null): array
    {
        // 如果没有传入控制器类，自动扫描
        if ($controllerClasses === null) {
            $controllerClasses = $this->getAllControllerClasses();
        }

        $swagger = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => $this->config['title'],
                'description' => $this->config['description'],
                'version' => $this->config['version']
            ],
            'servers' => [
                [
                    'url' => $this->config['schemes'][0] . '://' . $this->config['host'],
                    'description' => '开发服务器'
                ]
            ],
            'paths' => [],
            'components' => [
                'schemas' => [
                    'Error' => [
                        'type' => 'object',
                        'properties' => [
                            'error' => [
                                'type' => 'string',
                                'description' => '错误信息'
                            ],
                            'message' => [
                                'type' => 'string',
                                'description' => '详细的错误信息'
                            ]
                        ]
                    ]
                ]
            ]
        ];

        foreach ($controllerClasses as $class) {
            $reflection = new ReflectionClass($class);
            $methods = $reflection->getMethods();

            foreach ($methods as $method) {
                $docComment = $method->getDocComment();
                $routeInfo = $this->parseRouteFromComment($docComment);

                if ($routeInfo !== null) {
                    [$httpMethod, $path] = $routeInfo;

                    // 解析方法文档
                    $operation = $this->parseMethodDocumentation($method, $docComment);

                    // 解析参数 - 传递路由路径信息
                    $operation['parameters'] = $this->parseParameters($method, $docComment, $path);

                    // 解析响应
                    $operation['responses'] = $this->parseResponses($method, $docComment);

                    // 解析请求体（如果是POST/PUT等方法）
                    if (in_array(strtoupper($httpMethod), ['POST', 'PUT', 'PATCH'])) {
                        $requestBody = $this->parseRequestBody($method, $docComment);
                        if ($requestBody) {
                            $operation['requestBody'] = $requestBody;
                        }
                    }

                    // 初始化路径
                    if (!isset($swagger['paths'][$path])) {
                        $swagger['paths'][$path] = [];
                    }

                    $swagger['paths'][$path][strtolower($httpMethod)] = $operation;
                }
            }
        }

        return $swagger;
    }

    private function parseRouteFromComment(string $docComment): ?array
    {
        $pattern = '/@route\s+([A-Z]+)\s+(\/[^\s]+)/i';
        preg_match($pattern, $docComment, $matches);

        if (isset($matches[1]) && isset($matches[2])) {
            return [
                strtoupper(trim($matches[1])),
                trim($matches[2])
            ];
        }

        return null;
    }

    private function parseMethodDocumentation(ReflectionMethod $method, string $docComment): array
    {
        $lines = explode("\n", $docComment);
        $summary = '';
        $description = '';

        foreach ($lines as $line) {
            $line = trim($line);
            // 移除注释标记
            $line = preg_replace('/^\/\*\*|^\s*\*\s?|^\s*\*\/$/', '', $line);

            if (empty($line) || str_starts_with($line, '@')) {
                continue;
            }

            if (empty($summary) && !empty(trim($line))) {
                $summary = trim($line);
            } elseif (!empty($summary)) {
                $description .= trim($line) . ' ';
            }
        }

        $description = trim($description);

        return [
            'summary' => $summary ?: $method->getName(),
            'description' => $description,
            'tags' => [$this->getControllerTag($method->getDeclaringClass()->getName())]
        ];
    }

    private function getControllerTag(string $className): string
    {
        $parts = explode('\\', $className);
        $name = end($parts);
        // 移除末尾的 Controller
        if (str_ends_with($name, 'Controller')) {
            $name = substr($name, 0, -strlen('Controller'));
        }
        return $name;
    }

    private function parseParameters(ReflectionMethod $method, string $docComment, string $routePath): array
    {
        $parameters = [];
        $paramLines = $this->extractTagLines($docComment, 'param');

        foreach ($paramLines as $line) {
            $paramInfo = $this->parseParamLine($line);
            if ($paramInfo) {
                $parameter = [
                    'name' => $paramInfo['name'],
                    'in' => $this->getParameterLocation($paramInfo['name'], $method, $routePath),
                    'description' => $paramInfo['description'] ?? '',
                    'required' => $this->isParameterRequired($method, $paramInfo['name']),
                    'schema' => [
                        'type' => $this->getSwaggerType($paramInfo['type'])
                    ]
                ];

                // 添加验证规则信息
                $validationRules = $this->extractValidationRules($line);
                if (!empty($validationRules)) {
                    $parameter = $this->applyValidationRules($parameter, $validationRules);
                }

                $parameters[] = $parameter;
            }
        }

        return $parameters;
    }

    private function extractTagLines(string $docComment, string $tag): array
    {
        $lines = explode("\n", $docComment);
        $tagLines = [];

        foreach ($lines as $line) {
            if (preg_match('/\*' . '\s*@' . $tag . '\s+(.*)/', $line, $matches)) {
                $tagLines[] = trim($matches[1]);
            }
        }

        return $tagLines;
    }

    private function parseParamLine(string $paramLine): ?array
    {
        // 匹配 @param type $name description {@v rules}
        $pattern = '/^([^\s]+)\s+\$([^\s]+)\s*(.*?)(?:\s*{\@v\s*([^}]*)})?$/';
        if (preg_match($pattern, $paramLine, $matches)) {
            return [
                'type' => $matches[1],
                'name' => $matches[2],
                'description' => trim($matches[3]),
                'validation' => $matches[4] ?? null
            ];
        }

        return null;
    }

    private function getParameterLocation(string $paramName, ReflectionMethod $method, string $routePath): string
    {
        // 检查路由路径中是否包含此参数名作为路径参数
        if (str_contains($routePath, '{' . $paramName . '}')) {
            return 'path';
        }

        // 检查方法参数中是否包含特殊类型
        foreach ($method->getParameters() as $param) {
            if ($param->getName() === $paramName) {
                $type = $param->getType();
                if ($type instanceof ReflectionNamedType) {
                    if ($type->getName() === 'Psr\Http\Message\ServerRequestInterface') {
                        return 'header'; // Request对象通常从请求头获取
                    }
                }
            }
        }

        return 'query'; // 默认为查询参数
    }

    private function isParameterRequired(ReflectionMethod $method, string $paramName): bool
    {
        foreach ($method->getParameters() as $param) {
            if ($param->getName() === $paramName) {
                return !$param->isOptional();
            }
        }
        return false;
    }

    private function getSwaggerType(string $phpType): string
    {
        $typeMap = [
            'int' => 'integer',
            'float' => 'number',
            'double' => 'number',
            'bool' => 'boolean',
            'array' => 'array',
            'object' => 'object',
            'string' => 'string'
        ];

        return $typeMap[strtolower($phpType)] ?? 'string';
    }

    private function extractValidationRules(string $paramLine): array
    {
        $pattern = '/{\@v\s*([^}]*)}/';
        if (preg_match($pattern, $paramLine, $matches)) {
            $rules = trim($matches[1]);
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

        return [];
    }

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
                    $parameter['schema']['enum'] = [$value];
                    break;
                case 'different':
                    // 不同于某个值（Swagger不直接支持，但可用于文档说明）
                    $parameter['description'] .= ' (不能等于 ' . $value . ')';
                    break;
                case 'accepted':
                    // 布尔值接受规则
                    $parameter['schema']['type'] = 'boolean';
                    break;
                case 'numeric':
                    // 数字验证
                    if ($parameter['schema']['type'] !== 'number' && $parameter['schema']['type'] !== 'integer') {
                        $parameter['schema']['type'] = 'number';
                    }
                    break;
                case 'integer':
                    // 整数验证
                    $parameter['schema']['type'] = 'integer';
                    break;
                case 'boolean':
                    // 布尔值验证
                    $parameter['schema']['type'] = 'boolean';
                    break;
                case 'array':
                    // 数组验证
                    $parameter['schema']['type'] = 'array';
                    break;
                case 'length':
                    // 固定长度
                    $parameter['schema']['minLength'] = (int) $value;
                    $parameter['schema']['maxLength'] = (int) $value;
                    break;
                case 'lengthBetween':
                    // 长度范围
                    $range = explode(',', $value);
                    if (count($range) === 2) {
                        $parameter['schema']['minLength'] = (int) $range[0];
                        $parameter['schema']['maxLength'] = (int) $range[1];
                    }
                    break;
                case 'lengthMin':
                    // 最小长度
                    $parameter['schema']['minLength'] = (int) $value;
                    break;
                case 'lengthMax':
                    // 最大长度
                    $parameter['schema']['maxLength'] = (int) $value;
                    break;
                case 'min':
                    // 最小值
                    $parameter['schema']['minimum'] = (int) $value;
                    break;
                case 'max':
                    // 最大值
                    $parameter['schema']['maximum'] = (int) $value;
                    break;
                case 'in':
                    // 允许的值
                    $values = explode(',', $value);
                    $parameter['schema']['enum'] = array_map('trim', $values);
                    break;
                case 'notIn':
                    // 禁止的值（Swagger不直接支持）
                    $parameter['description'] .= ' (不能是: ' . $value . ')';
                    break;
                case 'ip':
                    // IP地址格式
                    $parameter['schema']['format'] = 'ipv4';
                    break;
                case 'email':
                    // 邮箱格式
                    $parameter['schema']['format'] = 'email';
                    break;
                case 'url':
                    // URL格式
                    $parameter['schema']['format'] = 'uri';
                    break;
                case 'urlActive':
                    // 活跃URL（增强的URL验证）
                    $parameter['schema']['format'] = 'uri';
                    break;
                case 'alpha':
                    // 字母验证
                    $parameter['schema']['pattern'] = '^[a-zA-Z]+$';
                    break;
                case 'alphaNum':
                    // 字母数字验证
                    $parameter['schema']['pattern'] = '^[a-zA-Z0-9]+$';
                    break;
                case 'slug':
                    // URL友好格式
                    $parameter['schema']['pattern'] = '^[a-z0-9_-]+$';
                    break;
                case 'regex':
                    // 正则表达式
                    $parameter['schema']['pattern'] = $value;
                    break;
                case 'date':
                    // 日期格式
                    $parameter['schema']['format'] = 'date';
                    break;
                case 'dateFormat':
                    // 指定日期格式（Swagger不直接支持，但可用于文档说明）
                    $parameter['description'] .= ' (日期格式: ' . $value . ')';
                    $parameter['schema']['format'] = 'date';
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
                    $parameter['schema']['pattern'] = '^[0-9 ]+$';
                    break;
            }
        }

        return $parameter;
    }

    private function parseResponses(ReflectionMethod $method, string $docComment): array
    {
        $responses = [
            '200' => [
                'description' => 'Successful response',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object'
                        ]
                    ]
                ]
            ]
        ];

        // 检查是否有错误响应
        if ($this->hasErrorResponses($docComment)) {
            $responses['400'] = [
                'description' => 'Bad request - Validation error',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            '$ref' => '#/components/schemas/Error'
                        ]
                    ]
                ]
            ];
        }

        // 检查返回类型
        $returnType = $method->getReturnType();
        if ($returnType) {
            $returnTypeName = $returnType->getName();
            if ($returnTypeName === 'void') {
                $responses['204'] = [
                    'description' => 'No content'
                ];
                unset($responses['200']);
            }
        }

        return $responses;
    }

    private function hasErrorResponses(string $docComment): bool
    {
        return str_contains($docComment, '{@v') || str_contains($docComment, '@param');
    }

    private function parseRequestBody(ReflectionMethod $method, string $docComment): ?array
    {
        $properties = [];
        $required = [];
        $paramLines = $this->extractTagLines($docComment, 'param');

        foreach ($paramLines as $line) {
            $paramInfo = $this->parseParamLine($line);
            if ($paramInfo && !in_array($paramInfo['type'], ['Psr\Http\Message\ServerRequestInterface', 'Request'])) {
                $property = [
                    'type' => $this->getSwaggerType($paramInfo['type']),
                    'description' => $paramInfo['description'] ?? ''
                ];

                // 应用验证规则到属性
                $validationRules = $this->extractValidationRules($line);
                if (!empty($validationRules)) {
                    $property = $this->applyPropertyValidationRules($property, $validationRules);
                }

                $properties[$paramInfo['name']] = $property;

                // 检查是否必需
                $paramIsRequired = false;
                foreach ($method->getParameters() as $param) {
                    if ($param->getName() === $paramInfo['name']) {
                        $paramIsRequired = !$param->isOptional();
                        break;
                    }
                }

                if (isset($validationRules['required']) || $paramIsRequired) {
                    $required[] = $paramInfo['name'];
                }
            }
        }

        if (empty($properties)) {
            return null;
        }

        $requestBody = [
            'required' => true,
            'content' => [
                'application/json' => [
                    'schema' => [
                        'type' => 'object',
                        'properties' => $properties
                    ]
                ]
            ]
        ];

        if (!empty($required)) {
            $requestBody['content']['application/json']['schema']['required'] = $required;
        }

        return $requestBody;
    }

    private function applyPropertyValidationRules(array $property, array $validationRules): array
    {
        foreach ($validationRules as $rule => $value) {
            switch ($rule) {
                case 'required':
                    $property['required'] = true;
                    break;
                case 'optional':
                    $property['required'] = false;
                    break;
                case 'equals':
                    $property['enum'] = [$value];
                    break;
                case 'numeric':
                    if ($property['type'] !== 'number' && $property['type'] !== 'integer') {
                        $property['type'] = 'number';
                    }
                    break;
                case 'integer':
                    $property['type'] = 'integer';
                    break;
                case 'boolean':
                    $property['type'] = 'boolean';
                    break;
                case 'array':
                    $property['type'] = 'array';
                    break;
                case 'length':
                    $property['minLength'] = (int) $value;
                    $property['maxLength'] = (int) $value;
                    break;
                case 'lengthBetween':
                    $range = explode(',', $value);
                    if (count($range) === 2) {
                        $property['minLength'] = (int) $range[0];
                        $property['maxLength'] = (int) $range[1];
                    }
                    break;
                case 'lengthMin':
                    $property['minLength'] = (int) $value;
                    break;
                case 'lengthMax':
                    $property['maxLength'] = (int) $value;
                    break;
                case 'min':
                    $property['minimum'] = (int) $value;
                    break;
                case 'max':
                    $property['maximum'] = (int) $value;
                    break;
                case 'in':
                    $values = explode(',', $value);
                    $property['enum'] = array_map('trim', $values);
                    break;
                case 'ip':
                    $property['format'] = 'ipv4';
                    break;
                case 'email':
                    $property['format'] = 'email';
                    break;
                case 'url':
                    $property['format'] = 'uri';
                    break;
                case 'alpha':
                    $property['pattern'] = '^[a-zA-Z]+$';
                    break;
                case 'alphaNum':
                    $property['pattern'] = '^[a-zA-Z0-9]+$';
                    break;
                case 'slug':
                    $property['pattern'] = '^[a-z0-9_-]+$';
                    break;
                case 'regex':
                    $property['pattern'] = $value;
                    break;
                case 'date':
                    $property['format'] = 'date';
                    break;
            }
        }

        return $property;
    }

    private function getAllControllerClasses(): array
    {
        $classes = [];
        $dir = __DIR__ . '/../../../app/Controllers'; // 修改为新的控制器目录

        if (!is_dir($dir)) {
            return $classes;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $content = file_get_contents($file->getPathname());

                // 查找类名
                if (
                    preg_match('/namespace\s+([^\s;]+)/', $content, $nsMatches) &&
                    preg_match('/class\s+(\w+)/', $content, $classMatches)
                ) {

                    $className = $nsMatches[1] . '\\' . $classMatches[1];
                    if (class_exists($className)) {
                        $classes[] = $className;
                    }
                }
            }
        }

        return $classes;
    }
}
