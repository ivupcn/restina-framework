<?php
// restina/Document.php

namespace Restina;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

class Document
{
    protected string $dir;

    protected array $schemas = [
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
    ];

    public function __construct(string $dir = __DIR__ . '/../../../app/Controllers')
    {
        $this->dir = $dir;
    }


    public function generate(?array $controllerClasses = null): array
    {
        // 如果没有传入控制器类，自动扫描
        if ($controllerClasses === null) {
            $controllerClasses = $this->getAllControllerClasses();
        }

        $swagger = [];

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
                    if (!isset($swagger[$path])) {
                        $swagger[$path] = [];
                    }

                    $swagger[$path][strtolower($httpMethod)] = $operation;
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
            // 移除首尾空白
            $line = trim($line);

            // 完全跳过注释开始和结束标记
            if ($line === '/**' || $line === '*/' || preg_match('/^\/\*\*+$/', $line)) {
                continue;
            }

            // 移除行开头的 * 及其周围的空白
            $cleanLine = preg_replace('/^\*\s?/', '', $line);

            // 跳过空行和注解标签
            if (empty($cleanLine) || str_starts_with(ltrim($cleanLine), '@')) {
                continue;
            }

            if (empty($summary) && !empty(trim($cleanLine))) {
                $summary = trim($cleanLine);
            } elseif (!empty($summary)) {
                $description .= trim($cleanLine) . ' ';
            }
        }

        $description = trim($description);

        $result = [
            'summary' => $summary ?: $method->getName(),
            'description' => $description,
            'tags' => [$this->getControllerTag($method->getDeclaringClass()->getName())]
        ];

        // 解析权限标识并添加到结果中
        $permissionId = $this->extractPermissionId($docComment);
        $result['permissionId'] = $permissionId ? $permissionId : null;

        return $result;
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

    /**
     * 提取权限标识
     */
    private function extractPermissionId(string $docComment): ?string
    {
        $permissionLines = $this->extractTagLines($docComment, 'permissionId');

        if (!empty($permissionLines)) {
            return trim($permissionLines[0]);
        }

        return null;
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
        $responses = [];

        // 首先检查是否有 @response 注解
        $responseLines = $this->extractTagLines($docComment, 'response');

        foreach ($responseLines as $line) {
            $responseInfo = $this->parseResponseLine($line);
            if ($responseInfo) {
                $statusCode = $responseInfo['status'];
                $description = $responseInfo['description'];
                $contentType = $responseInfo['content_type'] ?? 'application/json';

                $responses[$statusCode] = [
                    'description' => $description,
                    'content' => [
                        $contentType => [
                            'schema' => $this->getResponseSchema($responseInfo['type'] ?? null, $docComment)
                        ]
                    ]
                ];
            }
        }

        // 如果没有定义任何响应，则根据返回类型生成默认响应
        if (empty($responses)) {
            $returnType = $method->getReturnType();

            if ($returnType) {
                $returnTypeName = $returnType->getName();

                if ($returnTypeName === 'void') {
                    // void 类型返回 204 No Content
                    $responses['204'] = [
                        'description' => 'No content'
                    ];
                } else {
                    // 根据实际返回类型生成响应
                    $responses['200'] = [
                        'description' => 'Successful response',
                        'content' => [
                            'application/json' => [
                                'schema' => $this->createSchemaForType($returnTypeName)
                            ]
                        ]
                    ];
                }
            } else {
                // 如果没有返回类型声明，使用通用对象
                $responses['200'] = [
                    'description' => 'Successful response',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object'
                            ]
                        ]
                    ]
                ];
            }
        }

        // 添加常见的错误响应（如果没有显式定义的话）
        if (!isset($responses['400'])) {
            // 检查是否有参数验证规则，如果有则添加 400 错误响应
            if ($this->hasValidationRules($docComment)) {
                $responses['400'] = [
                    'description' => 'Bad Request - Validation error',
                    'content' => [
                        'application/json' => [
                            'schema' => $this->schemas['Error']
                        ]
                    ]
                ];
            }
        }

        $responses = $this->addCommonResponses($responses, $method, $docComment);

        return $responses;
    }

    /**
     * 解析 @response 注解行
     * 格式: @response 200 {"type": "User", "description": "Success response"}
     * 或者: @response 200 Success message
     */
    private function parseResponseLine(string $responseLine): ?array
    {
        // 匹配 @response status description 或 @response status type description
        $pattern = '/^(\d+)\s+(?:\{([^}]+)\}|([^\{].*))$/';
        if (preg_match($pattern, $responseLine, $matches)) {
            $status = $matches[1];

            if (isset($matches[2])) {
                // 解析 JSON 格式的响应定义
                $jsonDef = '{' . $matches[2] . '}';
                $data = json_decode($jsonDef, true);
                if ($data) {
                    return [
                        'status' => $status,
                        'type' => $data['type'] ?? null,
                        'description' => $data['description'] ?? 'Response for status ' . $status,
                        'content_type' => $data['content_type'] ?? 'application/json'
                    ];
                }
            }

            // 简单格式: 状态码 + 描述
            return [
                'status' => $status,
                'description' => trim($matches[3] ?? $matches[2]),
                'type' => null
            ];
        }

        return null;
    }

    /**
     * 获取响应的 Schema 定义
     */
    private function getResponseSchema(?string $type, string $docComment): array
    {
        if ($type) {
            return $this->createSchemaForType($type);
        }

        // 尝试从注释中解析返回值信息
        $returnLines = $this->extractTagLines($docComment, 'return');
        if (!empty($returnLines)) {
            $firstReturn = $returnLines[0];
            $pattern = '/^([^\s]+)(?:\s+(.+))?$/';
            if (preg_match($pattern, $firstReturn, $matches)) {
                $returnType = $matches[1];
                return $this->createSchemaForType($returnType);
            }
        }

        // 默认返回通用对象
        return [
            'type' => 'object'
        ];
    }

    /**
     * 根据类型创建 Schema
     */
    private function createSchemaForType(string $type): array
    {
        // 处理数组类型，如 User[]
        if (substr($type, -2) === '[]') {
            $itemType = substr($type, 0, -2);
            return [
                'type' => 'array',
                'items' => $this->createSchemaForType($itemType)
            ];
        }

        // 映射 PHP 类型到 Swagger 类型
        $typeMap = [
            'int' => 'integer',
            'float' => 'number',
            'double' => 'number',
            'bool' => 'boolean',
            'array' => 'array',
            'object' => 'object',
            'string' => 'string',
            'void' => 'null'
        ];

        $swaggerType = $typeMap[strtolower($type)] ?? 'object';

        $schema = ['type' => $swaggerType];

        // 对于对象类型，我们可以尝试引用组件中的定义（如果有的话）
        if ($swaggerType === 'object' && $type !== 'array' && $type !== 'object') {
            // 如果不是基本类型，假设它是一个模型
            $schema['$ref'] = '#/components/schemas/' . $type;
        }

        return $schema;
    }

    /**
     * 检查是否有验证规则
     */
    private function hasValidationRules(string $docComment): bool
    {
        return str_contains($docComment, '{@v') || $this->hasRequestParamsWithValidation($docComment);
    }

    /**
     * 检查参数是否有验证规则
     */
    private function hasRequestParamsWithValidation(string $docComment): bool
    {
        $paramLines = $this->extractTagLines($docComment, 'param');
        foreach ($paramLines as $line) {
            if (str_contains($line, '{@v')) {
                return true;
            }
        }
        return false;
    }

    /**
     * 添加常见的 HTTP 响应状态码
     */
    private function addCommonResponses(array $responses, ReflectionMethod $method, string $docComment): array
    {
        // 添加 401 Unauthorized (如果没有定义的话)
        if (!isset($responses['401'])) {
            if ($this->hasAuthAnnotation($docComment)) {
                $responses['401'] = [
                    'description' => 'Unauthorized'
                ];
            }
        }

        // 添加 403 Forbidden (如果没有定义的话)
        if (!isset($responses['403'])) {
            if ($this->hasAuthAnnotation($docComment) || $this->hasPermissionAnnotation($docComment)) {
                $responses['403'] = [
                    'description' => 'Forbidden'
                ];
            }
        }

        // 对于修改数据的方法，添加 422 Unprocessable Entity
        $methodName = strtolower($method->getName());
        $httpMethods = ['post', 'put', 'patch', 'delete'];

        $docCommentLower = strtolower($docComment);
        $needsValidationResponse = false;

        foreach ($httpMethods as $httpMethod) {
            if (preg_match("/@route\s+$httpMethod/i", $docCommentLower)) {
                $needsValidationResponse = true;
                break;
            }
        }

        if ($needsValidationResponse && !isset($responses['422'])) {
            $responses['422'] = [
                'description' => 'Unprocessable Entity - Validation failed',
                'content' => [
                    'application/json' => [
                        'schema' => $this->schemas['Error']
                    ]
                ]
            ];
        }

        return $responses;
    }

    /**
     * 检查是否有认证相关的注解
     */
    private function hasAuthAnnotation(string $docComment): bool
    {
        return str_contains(strtolower($docComment), '@auth') || str_contains(strtolower($docComment), '@jwt');
    }

    /**
     * 检查是否有权限相关的注解
     */
    private function hasPermissionAnnotation(string $docComment): bool
    {
        return str_contains(strtolower($docComment), '@permission') || str_contains(strtolower($docComment), '@permissionId') || str_contains(strtolower($docComment), '@role');
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

        if (!is_dir($this->dir)) {
            return $classes;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir)
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
