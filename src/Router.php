<?php
// restina/Router.php

namespace Restina;

use Psr\Http\Message\ServerRequestInterface;
use Restina\Request;
use Restina\Hook;

/**
 * 路由类
 */
class Router
{
    /**
     * 路由表
     *
     * @var array
     */
    private array $routes = [];

    /**
     * 路由Trie树根节点
     *
     * @var array|null
     */
    private ?array $trieRoot = null;

    /**
     * 创建一个新的Trie节点
     * 
     * @return array
     */
    private function createTrieNode(): array
    {
        return [
            'children' => [],
            'routeData' => null,      // 存储路由数据 [methods, handler, middlewares]
            'paramName' => null       // 动态参数名称
        ];
    }

    /**
     * 注册路由
     * 
     * @param array $routes 路由数据
     * @return array|null 返回构建好的Trie树根节点，或 null 如果没有路由
     */
    public function registerRoutes(array $routes): ?array
    {
        foreach ($routes as $route) {
            if (array_is_list($route)) {
                $method = $route[0];
                $path = $route[1];
                $handler = $route[2];
                $middlewares = $route[3] ?? [];
            } else {
                $method = $route['method'];
                $path = $route['path'];
                $handler = $route['handler'];
                $middlewares = $route['middlewares'] ?? [];
            }
            // 使用内部方法添加，避免频繁重置
            $this->addRouteInternal($method, $path, $handler, $middlewares);
        }
        // 批量添加完毕后，不再仅仅是重置 null，
        // 而是直接构建并返回树
        if (empty($routes)) {
            return null;
        }

        return $this->buildAndReturnTrie();
    }

    /**
     * 内部方法：仅添加路由到数组，不重置 Trie 树
     * @param string|array $method 请求方法
     * @param string $path 路由路径
     * @param callable $handler 处理函数
     * @param array $middleware 中间件
     * @return void
     */
    private function addRouteInternal(string|array $method, string $path, callable $handler, array $middlewares = []): void
    {
        // 支持通配符
        if ($method === '*') {
            $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];
        } else {
            $methods = is_string($method) ? [strtoupper($method)] : array_map('strtoupper', $method);
        }

        $this->routes[] = [
            'methods' => $methods,
            'path' => $path,
            'handler' => $handler,
            'middlewares' => $middlewares
        ];
    }

    /**
     * 添加路由
     *
     * @param string|array $method 请求方法
     * @param string $path 路由路径
     * @param callable $handler 处理函数
     * @param array $middleware 中间件
     * @return void
     */
    public function map(string|array $method, string $path, callable $handler, array $middlewares = []): void
    {
        // 使用内部方法添加
        $this->addRouteInternal($method, $path, $handler, $middlewares);

        // 单个添加时，重置 Trie 树
        $this->trieRoot = null;
    }

    /**
     * GET 请求
     * @param string $path 路由地址
     * @param callable $handler 请求处理函数
     * @param array $middlewares 中间件列表
     */
    public function get(string $path, callable $handler, array $middlewares = []): void
    {
        $this->map('GET', $path, $handler, $middlewares);
    }

    /**
     * 注册 POST 请求
     * @param string $path 路由地址
     * @param callable $handler 请求处理函数
     * @param array $middlewares 中间件列表
     */
    public function post(string $path, callable $handler, array $middlewares = []): void
    {
        $this->map('POST', $path, $handler, $middlewares);
    }

    /**
     * 注册 PUT 请求
     * @param string $path 路由地址
     * @param callable $handler 请求处理函数
     * @param array $middlewares 中间件列表
     */
    public function put(string $path, callable $handler, array $middlewares = []): void
    {
        $this->map('PUT', $path, $handler, $middlewares);
    }

    /**
     * 注册 PATCH 请求
     * @param string $path 路由地址
     * @param callable $handler 请求处理函数
     * @param array $middlewares 中间件列表
     */
    public function patch(string $path, callable $handler, array $middlewares = []): void
    {
        $this->map('PATCH', $path, $handler, $middlewares);
    }

    /**
     * 注册 DELETE 请求
     * @param string $path 路由地址
     * @param callable $handler 请求处理函数
     * @param array $middlewares 中间件列表
     */
    public function delete(string $path, callable $handler, array $middlewares = []): void
    {
        $this->map('DELETE', $path, $handler, $middlewares);
    }

    /**
     * 注册 HEAD 请求
     * @param string $path 路由地址
     * @param callable $handler 请求处理函数
     * @param array $middlewares 中间件列表
     */
    public function head(string $path, callable $handler, array $middlewares = []): void
    {
        $this->map('HEAD', $path, $handler, $middlewares);
    }

    /**
     * 注册 OPTIONS 请求
     * @param string $path 路由地址
     * @param callable $handler 请求处理函数
     * @param array $middlewares 中间件列表
     */
    public function options(string $path, callable $handler, array $middlewares = []): void
    {
        $this->map('OPTIONS', $path, $handler, $middlewares);
    }

    /**
     * 路由分组
     * @param string $prefix 路由前缀
     * @param callable $callback 回调函数
     * @return void
     */
    public function group(string $prefix, callable $callback): void
    {
        $originalRoutes = $this->routes;
        $groupRouter = new static();
        $callback($groupRouter);
        foreach ($groupRouter->getRoutes() as $route) {
            $route['path'] = rtrim($prefix, '/') . '/' . ltrim($route['path'], '/');
            $this->routes[] = $route;
        }
        // 重置路由树，下次调度时重建
        $this->trieRoot = null;
    }

    /**
     * 获取所有路由
     * @return array
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * 构建路由Trie树
     * 
     * @return void
     */
    private function buildTrie(): void
    {
        $this->trieRoot = $this->createTrieNode();
        foreach ($this->routes as $route) {
            $path = $route['path'];
            $pathSegments = $this->parsePathToSegments($path);

            $currentNode = &$this->trieRoot; // 使用引用避免重复复制

            foreach ($pathSegments as $segment) {
                if ($segment['type'] === 'static') {
                    $segmentValue = $segment['value'];
                    if (!isset($currentNode['children'][$segmentValue])) {
                        $currentNode['children'][$segmentValue] = $this->createTrieNode();
                    }
                    $currentNode = &$currentNode['children'][$segmentValue];
                } elseif ($segment['type'] === 'param') {
                    // 检查是否已有参数节点，如果没有则创建
                    if (!isset($currentNode['children']['__PARAM__'])) {
                        $paramNode = $this->createTrieNode();
                        $paramNode['paramName'] = $segment['name'];
                        $paramNode['children'] = []; // 参数节点也可以有子节点
                        $currentNode['children']['__PARAM__'] = $paramNode;
                    }
                    $currentNode = &$currentNode['children']['__PARAM__'];
                }
            }
            // 设置路由数据到最终节点
            if ($currentNode['routeData'] === null) {
                $currentNode['routeData'] = [];
            }
            // 合并相同路径的不同HTTP方法
            $currentNode['routeData'][] = [
                'methods' => $route['methods'],
                'handler' => $route['handler'],
                'path' => $route['path'],
                'middlewares' => $route['middlewares']
            ];
        }
    }

    /**
     * 解析路径为段数组
     * 
     * @param string $path
     * @return array
     */
    private function parsePathToSegments(string $path): array
    {
        $segments = [];
        $path = trim($path, '/');
        if ($path === '') {
            return [];
        }
        $parts = explode('/', $path);
        foreach ($parts as $part) {
            if (preg_match('/^\{([a-zA-Z0-9_]+)(?::([^}]+))?\}$/', $part, $matches)) {
                // 动态参数
                $segments[] = [
                    'type' => 'param',
                    'name' => $matches[1],
                    'regex' => isset($matches[2]) ? $matches[2] : '[^/]+'
                ];
            } else {
                // 静态段
                $segments[] = [
                    'type' => 'static',
                    'value' => $part
                ];
            }
        }
        return $segments;
    }

    /**
     * 运行路由
     * @param ServerRequestInterface|null $request 请求对象
     * @return mixed
     * @throws \Exception
     */
    public function dispatch(?ServerRequestInterface $request = null): mixed
    {
        if (!$request) {
            $request = Request::createFromGlobals();
        }
        $requestMethod = $request->getMethod();
        $requestUri = $request->getUri()->getPath();
        // 如果Trie树未构建，则构建它
        if ($this->trieRoot === null) {
            $this->buildTrie();
        }
        // 解析请求URI为段
        $requestSegments = $this->parseRequestUriToSegments($requestUri);
        // 在Trie树中搜索匹配的路由
        $result = $this->searchTrie($this->trieRoot, $requestSegments, 0, []);
        if ($result !== null) {
            [$routeData, $params] = $result;
            // 查找匹配请求方法的路由
            $matchedRoute = null;
            $allowedMethods = [];
            foreach ($routeData as $route) {
                if (in_array($requestMethod, $route['methods'])) {
                    $matchedRoute = $route;
                    break;
                } else {
                    $allowedMethods = array_merge($allowedMethods, $route['methods']);
                }
            }
            if ($matchedRoute) {
                // 注入参数
                foreach ($params as $key => $value) {
                    $request = $request->withAttribute($key, $value);
                }
                $pipeName = implode('_', $matchedRoute['methods']) . '_' . md5($matchedRoute['path']);
                // 注册路由特定的中间件管道
                foreach ($matchedRoute['middlewares'] as $index => $middleware) {
                    Hook::addPipe($pipeName, function ($request, $next) use ($middleware) {
                        return $middleware($request, $next);
                    }, 10 + $index);
                }
                return Hook::runPipe($pipeName, function () use ($matchedRoute, $request) {
                    return call_user_func($matchedRoute['handler'], $request);
                });
            } else {
                // 路径存在但方法不允许
                $allowedMethodsList = implode(', ', array_unique($allowedMethods));
                if ($requestMethod === 'OPTIONS') {
                    return null;
                }
                throw new \Exception('Method Not Allowed. Must be one of: ' . $allowedMethodsList, 405);
            }
        } else {
            // 未找到路径 (404)
            throw new \Exception('Not Found', 404);
        }
    }

    /**
     * 解析请求URI为段数组
     * 
     * @param string $uri
     * @return array
     */
    private function parseRequestUriToSegments(string $uri): array
    {
        $path = trim($uri, '/');
        if ($path === '') {
            return [];
        }
        return explode('/', $path);
    }

    /**
     * 在Trie树中搜索匹配的路由
     * 
     * @param array $node 当前节点
     * @param array $segments 请求路径段
     * @param int $index 当前处理的段索引
     * @param array $params 已收集的参数
     * @return array|null 返回 [routeData, params] 或 null
     */
    private function searchTrie(array $node, array $segments, int $index, array $params): ?array
    {
        // 如果已处理完所有段，检查当前节点是否有路由数据
        if ($index >= count($segments)) {
            if ($node['routeData'] !== null) {
                return [$node['routeData'], $params];
            }
            return null;
        }
        $currentSegment = $segments[$index];
        // 尝试匹配静态路径段
        if (isset($node['children'][$currentSegment])) {
            $result = $this->searchTrie(
                $node['children'][$currentSegment],
                $segments,
                $index + 1,
                $params
            );
            if ($result !== null) {
                return $result;
            }
        }
        // 尝试匹配参数路径段
        if (isset($node['children']['__PARAM__'])) {
            $paramNode = $node['children']['__PARAM__'];
            $newParams = $params;
            $newParams[$paramNode['paramName']] = $currentSegment;
            $result = $this->searchTrie(
                $paramNode,
                $segments,
                $index + 1,
                $newParams
            );
            if ($result !== null) {
                return $result;
            }
        }
        return null;
    }

    /**
     * 获取路由调试信息
     * @return array
     */
    public function getDebugInfo(): array
    {
        return [
            'total_routes' => count($this->routes),
            'methods_used' => array_unique(
                array_merge(...array_column($this->routes, 'methods'))
            ),
            'paths' => array_column($this->routes, 'path'),
            'has_dynamic_paths' => $this->hasDynamicPaths()
        ];
    }

    /**
     * 执行带中间件的处理器
     * @param array $middlewares
     * @param callable $finalHandler
     * @param ServerRequestInterface $request
     * @return mixed
     */
    private function executeWithMiddleware(array $middlewares, callable $finalHandler, ServerRequestInterface $request): mixed
    {
        $handler = $finalHandler;
        // 从后往前构建中间件链
        foreach (array_reverse($middlewares) as $middleware) {
            $handler = function ($request) use ($middleware, $handler) {
                return $middleware($request, $handler);
            };
        }
        return $handler($request);
    }

    /**
     * 匹配路由
     * 检查请求 URI 是否与路由路径模式匹配，并提取路由参数
     * @param string $routePath
     * @param string $requestUri
     * @return array|false 匹配成功返回参数数组，失败返回 false
     */
    private function matchPath(string $routePath, string $requestUri): array|false
    {
        // 将 {param} 或 {param:[0-9]+} 替换为正则命名捕获组
        $pattern = preg_replace_callback('/\{([a-zA-Z0-9_]+)(?::([^}]+))?\}/', function ($matches) {
            $paramName = $matches[1];
            $customRegex = $matches[2] ?? '[^/]+';
            return '(?P<' . $paramName . '>' . $customRegex . ')';
        }, $routePath);
        // 加上起始和结束符进行完全匹配
        $pattern = '#^' . $pattern . '$#u'; // 添加 u 修饰符支持 UTF-8
        if (preg_match($pattern, $requestUri, $matches)) {
            // 过滤掉数字键名的匹配项，只保留命名参数
            return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
        }
        return false;
    }

    /**
     * 检查是否存在动态路由路径
     * @return bool
     */
    private function hasDynamicPaths(): bool
    {
        foreach ($this->routes as $route) {
            if (strpos($route['path'], '{') !== false && strpos($route['path'], '}') !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 构建并返回 Trie 树
     * 
     * @return array 返回构建好的 Trie 树根节点
     * @throws \Exception 如果构建失败
     */
    public function buildAndReturnTrie(): array
    {
        // 1. 重置 Trie 树状态，确保重新构建
        $this->trieRoot = null;

        // 2. 调用原有的构建逻辑
        $this->buildTrie();

        // 3. 安全检查
        if ($this->trieRoot === null) {
            throw new \Exception('Failed to build Trie tree. The root node is null.', 500);
        }

        // 4. 返回构建好的树结构
        return $this->trieRoot;
    }
}
