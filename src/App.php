<?php
// restina/App.php

namespace Restina;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Restina\Hook;
use Restina\Container;
use Restina\Db;
use Restina\Middleware;
use Restina\Jwt;
use Restina\Attribute;
use Restina\Router;
use Restina\ExceptionHandler;

/**
 * @author 飞翔的蓝 <ivup@ivup.cn>
 * @property \Restina\Cache $cache
 * @property \Restina\Config $config
 * @property \Restina\Db $db
 * @property \Restina\Jwt $jwt
 * @property \Restina\Attribute $attribute
 * @property \Restina\Router $router
 */
class App
{
    private static ?self $instance = null;
    private Controller $controller;
    private Container $diContainer;
    private Config $config;
    private Cache $cache;
    private Db $db;
    private Jwt $jwt;
    private Attribute $attribute;
    private Router $router;
    private bool $isDebugMode;
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
     * 服务名称映射表 - 将别名映射到实际类名
     */
    private const SERVICE_NAME_MAP = [
        'cache' => Cache::class,
        'config' => Config::class,
        'db' => Db::class,
        'jwt' => Jwt::class,
        'attribute' => Attribute::class,
        'router' => Router::class,
    ];

    /**
     * 静态工厂方法
     */
    public static function init(array $options = []): self
    {
        if (self::$instance === null) {
            self::$instance = new static($options);
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
     * 构造函数
     */
    public function __construct(array $options = [])
    {
        // 初始化路径
        $this->initializePaths();
    }

    /**
     * 魔术方法 - 支持通过 $app->property 访问容器中的服务
     */
    public function __get(string $property)
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
                "Property '%s' does not exist or is not accessible through the container.",
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
    public function run(): void
    {
        try {
            // 自动加载控制器
            $this->setupControllers();
            $this->setupMiddlewares();
            Hook::doAction('app.started', $this);
            $this->router->dispatch();
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
        $this->rootPath = dirname(dirname(dirname(dirname($this->restinaPath))));
        $this->appPath = $this->rootPath . DIRECTORY_SEPARATOR . 'app';
        $this->runtimePath = $this->rootPath . DIRECTORY_SEPARATOR . 'runtime';
        $this->viewPath = $this->appPath . DIRECTORY_SEPARATOR . 'views';
        $this->cachePath = $this->runtimePath . DIRECTORY_SEPARATOR . 'cache';
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
        // 添加错误处理中间件
        // $errorMiddleware = $this->slimApp->addErrorMiddleware($this->isDebugMode, true, true);
        // $defaultErrorHandler = $errorMiddleware->getDefaultErrorHandler();
        // $defaultErrorHandler->forceContentType('application/json');
        // $defaultErrorHandler->registerErrorRenderer(
        //     'application/json',
        //     ExceptionHandler::class
        // );
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
        // 创建依赖注入容器
        $this->diContainer = new Container();
        // 创建缓存实例
        $this->cache = new Cache($this->config, $this->cachePath);
        // 创建数据库实例
        $this->db = new Db($this->config);
        // 创建JWT实例
        $this->jwt = new Jwt($this->config);
        // 创建属性注解服务
        $this->attribute = new Attribute($this->appPath . DIRECTORY_SEPARATOR . 'Controllers');
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
        $this->diContainer->set(static::class, $this);
        $this->diContainer->set(self::class, $this);
        $this->diContainer->set(Config::class, $this->config);
        $this->diContainer->set(Cache::class, $this->cache);
        $this->diContainer->set(Db::class, $this->db);
        $this->diContainer->set(Jwt::class, $this->jwt);
        $this->diContainer->set(Attribute::class, $this->attribute);
        $this->diContainer->set(Router::class, $this->router);
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
        // $manager = new Middleware($this->slimApp, $this->diContainer, $this->isDebugMode, $this);
        // $manager->registerMiddlewares($this->appPath);
    }

    // 控制器设置
    private function setupControllers(): void
    {
        if ($this->isDebugMode) {
            $routes = $this->attribute->getRouteCollector();
        } else {
            // 尝试从缓存获取路由信息
            $routesKey = 'routes';
            $routes = $this->cache->get($routesKey);
            if (empty($routes)) {
                $doc = $this->attribute->getRouteCollector();
                // 缓存路由信息（24小时）
                $this->cache->set($routesKey, $routes, 86400);
            }
        }
        $this->controller->loadRoutes($routes);
    }
}
