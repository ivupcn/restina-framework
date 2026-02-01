<?php
// restina/App.php

namespace Restina;

use Slim\Factory\AppFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Restina\Hook;
use Restina\Container;
use Restina\Db;

class App
{
    private \Slim\App $slimApp;
    private Controller $controller;
    private Container $diContainer;
    private Config $config;
    private Cache $cache;
    private Db $db;
    private string $isDebugMode;
    private string $restinaPath;
    private string $rootPath;
    private string $cachePath;
    private string $appPath;
    private string $viewPath;
    private string $runtimePath;
    private array $serviceProviders = [];
    private bool $registered = false;
    private bool $bootstrapped = false;

    /**
     * 静态工厂方法
     */
    public static function init(array $options = []): self
    {
        return new static($options);
    }

    /**
     * 构造函数
     */
    public function __construct(array $options = [])
    {
        // 初始化路径
        $this->initializePaths();
        // 初始化 Slim
        $this->initializeSlimApp();
    }

    /**
     * 启动应用
     */
    public function boot(): self
    {
        if ($this->bootstrapped) {
            return $this;
        }

        // 加载应用配置
        $this->loadAppConfiguration();
        // 设置调试模式
        $this->setDebugMode();
        // 载入Hook配置
        $this->loadHookConfig();
        // 注册核心服务阶段
        $this->registerCoreServices();
        // 注册自定义服务阶段
        $this->registerCustomProviders();
        // 启动服务阶段
        $this->bootCoreServices();
        // 启动完成
        $this->bootstrapped = true;
        return $this;
    }

    /**
     * 运行应用
     */
    public function run(): void
    {
        try {
            // 自动加载控制器
            $this->setupControllers();
            $this->setupMiddlewares();
            Hook::doAction('app.started', $this);
            $this->slimApp->run();
        } catch (\Throwable $e) {
            error_log($e->getMessage());
            if ($this->isDebugMode) {
                throw $e;
            }
        } finally {
            // Terminate 阶段
            $this->terminate();
        }
    }

    /**
     * 注册服务到容器
     */
    public function bind(string $abstract, mixed $concrete = null): self
    {
        if ($concrete === null) {
            $concrete = $abstract;
        }

        $this->diContainer->set($abstract, $concrete);
        return $this;
    }

    /**
     * 从容器获取服务
     */
    public function resolve(string $abstract)
    {
        return $this->diContainer->get($abstract);
    }

    /**
     * 从容器创建实例
     */
    public function make(string $className, array $parameters = [])
    {
        if ($this->diContainer->has($className)) {
            return $this->diContainer->get($className);
        }

        return $this->diContainer->make($className, $parameters);
    }

    /**
     * 获取配置
     */
    public function getConfig(?string $key = null, mixed $default = null): mixed
    {
        if ($this->config === null) {
            $this->loadAppConfiguration();
        }
        return $this->config->get($key, $default);
    }

    /**
     * 获取缓存实例
     */
    public function getCache(): Cache  // 修正：返回 Cache 实例
    {
        return $this->cache;
    }

    /**
     * 获取缓存值
     */
    public function getCacheValue(string $key, mixed $default = null): mixed  // 新增：获取缓存值的方法
    {
        return $this->cache->get($key, $default);
    }

    public function getSlimApp(): \Slim\App
    {
        return $this->slimApp;
    }

    public function getViewPath(): string
    {
        return $this->viewPath;
    }

    public function getCachePath(): string
    {
        return $this->cachePath;
    }

    public function isDebugMode(): string
    {
        return $this->isDebugMode;
    }

    /**
     * 检查是否已注册服务
     */
    public function isRegistered(): bool
    {
        return $this->registered;
    }

    /**
     * 检查是否已引导
     */
    public function isBootstrapped(): bool
    {
        return $this->bootstrapped;
    }

    // 添加获取数据库管理器的方法
    public function getDb(): Db
    {
        return $this->db;
    }

    /**
     * 初始化路径
     */
    private function initializePaths(): void
    {
        $this->restinaPath = __DIR__;
        $this->rootPath = dirname($this->restinaPath);
        $this->appPath = $this->rootPath . DIRECTORY_SEPARATOR . 'app';
        $this->runtimePath = $this->rootPath . DIRECTORY_SEPARATOR . 'runtime';
        $this->viewPath = $this->appPath . DIRECTORY_SEPARATOR . 'views';
        $this->cachePath = $this->runtimePath . DIRECTORY_SEPARATOR . 'cache';
    }

    /**
     * 初始化 Slim 应用
     */
    private function initializeSlimApp(): void
    {
        $this->slimApp = AppFactory::create();
    }

    /**
     * 加载配置
     */
    private function loadAppConfiguration(): self
    {
        $configPath = $this->appPath . DIRECTORY_SEPARATOR . 'config.php';
        $configData = [];
        if (file_exists($configPath)) {
            $loadedConfig = require $configPath;
            if (is_array($loadedConfig)) {
                $configData = $loadedConfig;
            }
        }
        $this->config = new Config($configData);
        return $this;
    }

    /**
     * 设置调试模式
     */
    private function setDebugMode(): self
    {
        $this->isDebugMode = $this->config->get('app.debug', false);
        return $this;
    }

    /**
     * 从配置文件加载钩子
     */
    private function loadHookConfig(): void
    {
        $hooksPath = $this->appPath . DIRECTORY_SEPARATOR . 'hooks.php';

        if (file_exists($hooksPath)) {
            $hooks = require $hooksPath;
            // 直接使用数组而不是 HookConfig 对象
            Hook::loadFromConfig($hooks); // 修正方法名
        }
    }

    /**
     * 注册核心服务
     */
    private function registerCoreServices(): void
    {
        if ($this->registered) {
            return;
        }
        // 创建缓存实例
        $this->cache = new Cache($this->config, $this->cachePath);
        // 创建数据库实例
        $this->db = new Db($this->config);
        // 创建依赖注入容器
        $this->diContainer = new Container();
        // 将核心服务绑定到容器
        $this->bindCoreServicesToContainer();
        $this->registered = true;
    }

    /**
     * 注册自定义服务
     */
    private function registerCustomProviders(): void
    {
        foreach ($this->serviceProviders as $provider) {
            $instance = $this->make($provider);
            $instance->register($this);
        }
    }

    /**
     * 绑定核心服务到容器
     */
    private function bindCoreServicesToContainer(): void
    {
        $this->diContainer->set(static::class, $this);
        $this->diContainer->set(self::class, $this);
        $this->diContainer->set(Config::class, $this->config);
        $this->diContainer->set(Cache::class, $this->cache);
        $this->diContainer->set(Db::class, $this->db);
    }

    /**
     * 引导核心服务
     */
    private function bootCoreServices(): void
    {
        foreach ($this->serviceProviders as $provider) {
            $instance = $this->make($provider);
            $instance->boot($this);
        }
        $this->controller = new Controller($this, $this->diContainer);
        Hook::doAction('app.bootstrap', $this);
    }

    /**
     * 终止阶段
     */
    private function terminate(): void
    {
        // 清理资源
        if ($this->cache && $this->cache->isUsingRedis()) {
            $this->cache->close();
        }
    }

    // 中间件设置
    private function setupMiddlewares(): void
    {
        // 添加 Body 解析中间件
        $this->slimApp->addBodyParsingMiddleware();
        // 添加错误处理中间件
        $this->slimApp->addErrorMiddleware(!$this->isDebugMode, true, true);
    }

    // 控制器设置
    private function setupControllers(): void
    {
        $directory = $this->appPath . DIRECTORY_SEPARATOR . 'Controllers';

        if (!is_dir($directory)) {
            throw new \InvalidArgumentException("Controller directory not found: { $directory}");
        }

        // 使用缓存键
        $controllerClassesKey = 'controller_classes';
        $routesKey = 'routes';

        // 检查是否在调试模式下，如果是则跳过缓存
        if ($this->isDebugMode) {
            $classes = $this->findControllerClasses($directory);
            $routes = $this->parseRoutesFromControllers($classes);
        } else {
            // 尝试从缓存获取控制器类列表
            $classes = $this->cache->get($controllerClassesKey);
            if ($classes === null) {
                $classes = $this->findControllerClasses($directory);
                // 缓存控制器类列表（24小时）
                $this->cache->set($controllerClassesKey, $classes, 86400);
            }

            // 尝试从缓存获取路由信息
            $routes = $this->cache->get($routesKey);
            if ($routes === null) {
                $routes = $this->parseRoutesFromControllers($classes);
                // 缓存路由信息（24小时）
                $this->cache->set($routesKey, $routes, 86400);
            }
        }

        $this->controller->loadRoutes($routes);
    }

    private function findControllerClasses(string $directory): array
    {
        $classes = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = substr($file->getPathname(), strlen($directory) + 1);
            $className = str_replace(['/', '.php'], ['\\', ''], $relativePath);
            $fullClassName = "App\\Controllers\\" . $className;

            // 验证类是否有效
            if (class_exists($fullClassName) && $this->hasRouteMethods($fullClassName)) {
                $classes[] = $fullClassName;
            }
        }

        return array_values(array_unique($classes)); // 去重
    }

    private function parseRoutesFromControllers(array $controllerClasses): array
    {
        $routes = [];

        foreach ($controllerClasses as $class) {
            $reflection = new \ReflectionClass($class);
            $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

            foreach ($methods as $method) {
                $docComment = $method->getDocComment();
                $routeInfo = $this->parseRouteFromComment($docComment);

                if ($routeInfo !== null) {
                    [$methodType, $path] = $routeInfo;

                    $routes[] = [
                        'class' => $class,
                        'method' => $method->getName(),
                        'httpMethod' => $methodType,
                        'path' => $path,
                        'docComment' => $docComment
                    ];
                }
            }
        }

        return $routes;
    }

    private function parseRouteFromComment(string $docComment): ?array
    {
        // 匹配 @route <method> <path> 格式
        $pattern = '/@route\s+([A-Z]+)\s+(\/[^\s]+)/i';
        preg_match($pattern, $docComment, $matches);

        if (isset($matches[1]) && isset($matches[2])) {
            return [
                strtoupper(trim($matches[1])),  // HTTP方法
                trim($matches[2])              // 路径
            ];
        }

        return null;
    }

    private function hasRouteMethods(string $className): bool
    {
        if (!class_exists($className)) {
            return false;
        }

        $ref = new \ReflectionClass($className);
        $methods = $ref->getMethods(\ReflectionMethod::IS_PUBLIC);
        foreach ($methods as $method) {
            $docComment = $method->getDocComment();
            if ($this->hasRoute($docComment)) {
                return true;
            }
        }
        return false;
    }

    private function hasRoute(string $docComment): bool
    {
        return $this->parseRouteFromComment($docComment) !== null;
    }
}
