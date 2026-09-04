<?php
// restina/App.php

namespace Restina;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Restina\Hook;
use Restina\Container;
use Restina\Db;
use Restina\Jwt;
use Restina\Attribute;
use Restina\Router;
use Restina\ExceptionHandler;
use Restina\Logger;
use Restina\Response;
use Restina\Http;
use Restina\Cache;
use Restina\Config;
use Restina\Console;

/**
 * @author 飞翔的蓝 <ivup@ivup.cn>
 * @property \Restina\Cache $cache
 * @property \Restina\Config $config
 * @property \Restina\Db $db
 * @property \Restina\Jwt $jwt
 * @property \Restina\Attribute $attribute
 * @property \Restina\Router $router
 * @property \Restina\Logger $logger
 * @property \Restina\Response $response
 * @property \Restina\Http $http
 */
class App
{
    private static ?self $instance = null;
    private Http $http;
    private Container $diContainer;
    private Config $config;
    private Cache $cache;
    private Db $db;
    private Jwt $jwt;
    private Attribute $attribute;
    private Router $router;
    private Logger $logger;
    private Response $response;
    private Queue $queue;
    private bool $isDebugMode;
    private string $restinaPath;
    private string $rootPath;
    private string $cachePath;
    private string $appPath;
    private string $viewPath;
    private string $runtimePath;
    private string $logPath;
    private array $serviceProviders = [];
    private bool $registered = false;
    private bool $bootstrapped = false;

    /**
     * 服务名称映射表 - 将别名映射到实际类名
     */
    private const SERVICE_NAME_MAP = [
        'cache' => Cache::class,
        'config' => Config::class,
        'db' => Db::class,
        'jwt' => Jwt::class,
        'attribute' => Attribute::class,
        'router' => Router::class,
        'logger' => Logger::class,
        'response' => Response::class,
    ];

    /**
     * 静态工厂方法
     */
    public static function init(): self
    {
        if (self::$instance === null) {
            self::$instance = new static();
        }

        return self::$instance;
    }

    /**
     * 获取当前实例（如果没有则返回null）
     */
    public static function getInstance(): ?self
    {
        return self::$instance;
    }

    /**
     * 重置单例实例（主要用于测试）
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * 构造函数
     */
    public function __construct()
    {
        // 初始化路径
        $this->initializePaths();
        // 确定运行模式
        $this->determineRunMode();
    }

    /**
     * 魔术方法 - 支持通过 $app->property 访问容器中的服务
     */
    public function __get(string $property): mixed
    {
        // 检查容器中是否有对应的服务
        if ($this->diContainer !== null) {
            // 首先检查是否直接存在于容器中
            if ($this->diContainer->isInstantiated($property)) {
                return $this->diContainer->get($property);
            }
            // 然后检查服务名称映射
            if (isset(self::SERVICE_NAME_MAP[$property])) {
                $serviceClass = self::SERVICE_NAME_MAP[$property];
                return $this->make($serviceClass);
            }
        }

        // 属性不存在时抛出异常
        throw new \OutOfBoundsException(
            sprintf(
                "属性 '%s' 不存在，或者无法通过容器访问。",
                $property
            )
        );
    }

    /**
     * 魔术方法 - 支持检查属性是否存在
     */
    public function __isset(string $property): bool
    {
        // 检查容器中是否有对应的服务
        if ($this->diContainer !== null) {
            // 检查是否直接存在于容器中
            if ($this->diContainer->isInstantiated($property)) {
                return true;
            }
            // 检查服务名称映射
            if (isset(self::SERVICE_NAME_MAP[$property])) {
                $serviceClass = self::SERVICE_NAME_MAP[$property];
                return $this->diContainer->isInstantiated($serviceClass);
            }
        }
        return false;
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
    public function run(): self
    {
        try {
            // 从控制器注解中提取路由规则并完成注册
            $this->registerRoutesFromAttributes();
            Hook::doAction('app.started', $this);
            if (RUN_MODE === 'worker') {
                $this->handleWorkerMode();
            } elseif (RUN_MODE === 'cli') {
                $this->handleCliMode();
            } else {
                $this->handleCgiMode();
            }
        } catch (\Throwable $e) {
            $this->processError($e);
        } finally {
            return $this;
        }
    }

    /**
     * 终止阶段
     */
    public function end(): void
    {
        if (RUN_MODE === 'cgi') {
            // 只在非 Worker 模式下清理资源
            if ($this->cache && $this->cache->isUsingRedis()) {
                $this->cache->close();
            }
            if ($this->logger) {
                $this->logger->write();
            }
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
        if ($this->diContainer->isInstantiated($className)) {
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
    public function getCache(): Cache  // 返回 Cache 实例
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

    /**
     * 获取根目录路径
     */
    public function getRootPath(): string
    {
        return $this->rootPath;
    }

    /**
     * 获取视图目录路径
     */
    public function getViewPath(): string
    {
        return $this->viewPath;
    }

    /**
     * 获取应用目录路径
     */
    public function getAppPath(): string
    {
        return $this->appPath;
    }

    /**
     * 获取缓存目录路径
     */
    public function getCachePath(): string
    {
        return $this->cachePath;
    }

    /**
     * 获取调试模式
     */
    public function isDebugMode(): bool
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
     * 获取队列管理器
     */
    public function getQueue(): Queue
    {
        if (!isset($this->queue)) {
            $this->queue = new Queue($this->config, $this->db);
        }
        return $this->queue;
    }

    /**
     * 初始化路径
     */
    private function initializePaths(): void
    {
        $this->restinaPath = __DIR__;
        $this->rootPath = dirname(dirname(dirname(dirname($this->restinaPath))));
        $this->appPath = $this->rootPath . DIRECTORY_SEPARATOR . 'app';
        $this->runtimePath = $this->rootPath . DIRECTORY_SEPARATOR . 'runtime';
        $this->viewPath = $this->appPath . DIRECTORY_SEPARATOR . 'views';
        $this->cachePath = $this->runtimePath . DIRECTORY_SEPARATOR . 'cache';
        $this->logPath = $this->runtimePath . DIRECTORY_SEPARATOR . 'logs';
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
        // 注册全局异常处理器
        set_exception_handler([$this, 'handleUncaughtException']);
        // 注册PHP错误处理器
        set_error_handler([$this, 'handlePhpError']);
        return $this;
    }

    /**
     * 从配置文件加载钩子
     */
    private function loadHookConfig(): void
    {
        $hooksPath = $this->appPath . DIRECTORY_SEPARATOR . 'hooks.php';
        if (file_exists($hooksPath)) {
            $hooks = require_once $hooksPath;
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
        // 创建日志实例
        $this->logger = new Logger($this->logPath);
        // 创建依赖注入容器
        $this->diContainer = new Container();
        // 创建响应实例
        $this->response = new Response();
        // 创建缓存实例
        $this->cache = new Cache($this->config, $this->cachePath);
        // 创建数据库实例
        $this->db = new Db($this->config);
        // 创建JWT实例
        $this->jwt = new Jwt($this->config);
        // 创建属性注解服务
        $this->attribute = new Attribute($this->appPath . DIRECTORY_SEPARATOR . 'controllers');
        // 创建路由实例
        $this->router = new Router();
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
        $this->diContainer->set(self::class, $this);
        $this->diContainer->set(Config::class, $this->config);
        $this->diContainer->set(Response::class, $this->response);
        $this->diContainer->set(Cache::class, $this->cache);
        $this->diContainer->set(Db::class, $this->db);
        $this->diContainer->set(Jwt::class, $this->jwt);
        $this->diContainer->set(Attribute::class, $this->attribute);
        $this->diContainer->set(Router::class, $this->router);
        $this->diContainer->set(Logger::class, $this->logger);
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
        $this->http = new Http($this, $this->diContainer);
        Hook::doAction('app.bootstrap', $this);
    }

    /**
     * 从控制器注解中提取路由规则并完成注册
     */
    private function registerRoutesFromAttributes(): void
    {
        if ($this->isDebugMode) {
            $routes = $this->attribute->getRouteCollector();
        } else {
            // 尝试从缓存获取路由信息
            $routesKey = 'routes';
            $routes = $this->cache->get($routesKey);
            if (empty($routes)) {
                $routes = $this->attribute->getRouteCollector();
                // 缓存路由信息（24小时）
                $this->cache->set($routesKey, $routes, 86400);
            }
        }
        $this->http->registerRoutes($routes);
    }

    /**
     * 运行 Web 模式
     */
    private function handleCgiMode(): void
    {
        $response = $this->handleRequest();
        $response->send();
    }

    /**
     * 运行命令行模式
     */
    private function handleCliMode(): void
    {
        $console = new Console($this);
        $console->run();
    }

    /**
     * 运行 Worker 模式
     * @return Response
     */
    private function handleWorkerMode(): void
    {
        while (frankenphp_handle_request(function () {
            $response = $this->handleRequest();
            gc_collect_cycles();
            return $response;
        }));
    }

    /**
     * 处理单个请求
     */
    private function handleRequest(): Response
    {
        return Hook::runPipe('request.middleware', function () {
            return $this->router->dispatch();
        });
    }

    /**
     * 处理错误
     */
    private function processError(\Throwable $exception): ?Response
    {
        // 使用自定义异常处理器
        $handler = new ExceptionHandler($this->logger, $this->isDebugMode);
        $response = $handler->handle(Request::createFromGlobals(), $exception);
        if (RUN_MODE === 'cgi') {
            $response->send();
            return $response;
        } elseif (RUN_MODE === 'cli') {
            fwrite(STDERR, (string) $response->getBody() . PHP_EOL);
            return $response;
        } else {
            return $response;
        }
    }

    /**
     * 处理未捕获的异常
     */
    public function handleUncaughtException(\Throwable $exception): void
    {
        $this->processError($exception);
    }

    /**
     * 处理PHP错误
     */
    public function handlePhpError(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        $error = new \ErrorException($errstr, 0, $errno, $errfile, $errline);
        $this->processError($error);
        return true; // 不执行PHP内部错误处理器
    }

    /**
     * 获取运行模式
     */
    private function determineRunMode(): void
    {
        $envMode = $_ENV['FRANKENPHP_MODE'] ?? $_SERVER['FRANKENPHP_MODE'] ?? null;
        $serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? '';
        // CLI 模式检测
        if (PHP_SAPI === 'cli') {
            $runMode = 'cli';
        } elseif (function_exists('frankenphp_handle_request')) {
            $runMode = 'worker';
        } elseif (!empty($envMode)) {
            $runMode = 'worker';
        } elseif (stripos($serverSoftware, 'FrankenPHP') !== false || stripos($serverSoftware, 'Caddy') !== false) {
            $runMode = 'worker';
        } else {
            $runMode = 'cgi';
        }
        if (!defined('RUN_MODE')) {
            define('RUN_MODE', $runMode);
        }
    }
}
