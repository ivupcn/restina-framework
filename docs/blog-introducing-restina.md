# Restina：一个 PHP 8.4 注解驱动的轻量级 API 框架

> 没有冗余的抽象，没有学不完的概念。写一个注解，路由就生效。

---

## 为什么我要造这个轮子

PHP 框架的选择很多——Laravel 功能全面，Symfony 架构严谨，Slim 极简灵活。但在实际做 REST API 项目的过程中，我始终觉得缺少一种"刚刚好"的感觉：

- **Laravel** 太重了。我只需要一个 API，却不得不面对 Blade、Broadcasting、Breeze 一大堆用不上的东西。
- **Slim / Flight** 足够轻，但路由注册、参数校验、依赖注入、文档生成这些工作全要自己拼凑。
- 大多数框架还在用 YAML、JSON 或者 PHP 数组配路由，每次加接口都要在路由文件和控制器之间来回跳。

我想要的其实很简单：

1. **一个注解就是一个路由**，不用在别处再注册一遍。
2. **参数校验写在注解里**，框架自动完成绑定和校验。
3. **API 文档自动生成**，不需要额外的注释。
4. **依赖注入开箱即用**，不用自己搭容器。
5. **够轻**，核心代码一目了然，出问题能直接看源码。

这就是 Restina。

---

## 30 秒看懂 Restina 的核心理念

在 Restina 中，你不需要写路由文件。你的控制器本身就是路由：

```php
<?php
namespace App\Controllers;

use Restina\attribute\Route;
use Restina\attribute\Params;
use Restina\attribute\enum\FieldType;

class UserController
{
    #[Route(methods: ['GET'], path: '/users/{id}', code: 'user.show')]
    #[Params(field: 'id', title: '用户ID', type: FieldType::INTEGER, rules: 'required|integer')]
    public function show(int $id): array
    {
        // $id 已经过类型转换和校验，直接用
        return ['code' => 0, 'data' => ['id' => $id, 'name' => '张三']];
    }
}
```

就这些。没有路由文件，没有 YAML 配置，没有中间件注册。

- `#[Route]` 告诉框架：这是一个 GET 接口，路径是 `/users/{id}`。
- `#[Params]` 告诉框架：`id` 是整数、必填、需要校验。
- 返回值是 `array`，框架自动 `json_encode` 输出。

**你写的每一行业务代码，同时也在定义 API 的契约。**

---

## 核心特性一览

### 1. 注解驱动路由

Restina 在启动时自动扫描 `app/controllers/` 下所有类的方法，提取 `#[Route]` 注解构建路由表。底层使用 **Trie 树** 进行路由匹配，支持动态参数 `{param}` 和正则约束 `{id:[0-9]+}`，性能远优于传统的正则遍历方案。

```php
// 支持所有 HTTP 方法
#[Route(methods: ['POST'], path: '/orders', code: 'order.create', jwt: true)]

// 公开接口，跳过 JWT 认证
#[Route(methods: ['GET'], path: '/health', jwt: false, permission: false)]

// 自动刷新 Token
#[Route(methods: ['GET'], path: '/profile', autoRefreshToken: true)]
```

### 2. 参数绑定 + 校验，一步到位

框架会自动从请求中提取与方法参数同名的变量，同时根据 `#[Params]` 中声明的类型和规则进行校验。校验失败直接返回 400，不需要你写一行 `if` 判断。

```php
#[Route(methods: ['POST'], path: '/users', code: 'user.create')]
#[Params(field: 'name', title: '用户名', type: FieldType::STRING, rules: 'required|lengthBetween:2,50')]
#[Params(field: 'email', title: '邮箱', type: FieldType::STRING, rules: 'required|email')]
#[Params(field: 'age', title: '年龄', type: FieldType::INTEGER, rules: 'integer|min:0|max:150')]
#[Params(field: 'role', title: '角色', type: FieldType::STRING, rules: 'in:admin,user,editor|optional')]
public function create(string $name, string $email, int $age = 0, string $role = 'user'): array
{
    // 走到这里，所有参数都已经过校验，放心使用
}
```

支持的校验规则覆盖了日常开发中的绝大多数场景：`required`、`email`、`url`、`ip`、`integer`、`numeric`、`min/max`、`lengthMin/lengthMax`、`in/notIn`、`date/dateFormat`、`regex`、`equals/different`、`contains` 等。

### 3. 依赖注入

Restina 自己实现了一个轻量级依赖注入容器 `Restina\Container`——基于 PHP 反射做自动装配，不引入任何第三方 DI 库。支持构造函数注入和 `#[Inject]` 属性注入两种方式：

```php
use Restina\attribute\Inject;

class OrderController
{
    // 构造函数注入 — 容器自动按类型解析
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    // 属性注入 — 更简洁
    #[Inject]
    private \Restina\Db $db;

    #[Inject(id: 'CacheService')]
    private $cacheService;  // 可以指定绑定 ID
}
```

核心服务（Config、Cache、Db、Jwt、Logger、Response 等）在框架启动时自动注册到容器，控制器中直接注入即可使用。

### 4. API 文档自动生成

Restina 内置了一套基于属性的文档生成系统。你不需要写任何额外的 Annotation——框架直接从 `#[Api]`、`#[Params]`、`#[Headers]`、`#[Returns]` 等属性中提取接口信息，生成结构化的文档数据。

```php
#[Docs(title: '用户管理', description: '用户增删改查接口', category: 'user')]
class UserController
{
    #[Route(methods: ['GET'], path: '/users/{id}', code: 'user.show')]
    #[Api(title: '获取用户详情', description: '根据 ID 返回用户信息', tags: ['用户'])]
    #[Params(field: 'id', title: '用户ID', type: FieldType::INTEGER, rules: 'required|integer')]
    #[Returns(field: 'code', title: '状态码', type: 'integer')]
    #[Returns(field: 'data', title: '用户信息', type: 'object', children: [
        ['field' => 'id', 'title' => '用户ID', 'type' => 'integer'],
        ['field' => 'name', 'title' => '用户名', 'type' => 'string'],
    ])]
    public function show(int $id): array { ... }
}
```

调用 `$app->attribute->generate()` 就能拿到结构化的文档数组，包含路由、参数、校验约束、响应结构等全部信息。校验规则（`required`、`min/max`、`email`、`in` 等）会自动映射为文档字段约束，不需要重复写。

**路由定义即文档，改代码就改文档，永远不会过期。**

### 5. Hook 系统 + 中间件管道

Restina 提供三种钩子类型，覆盖不同的扩展场景：

| 类型 | 用途 | 示例 |
|------|------|------|
| **Action** | 在特定生命周期节点执行逻辑 | `app.started`、`request.before_handle` |
| **Filter** | 对数据进行链式过滤和转换 | 请求数据清洗、响应格式化 |
| **Pipe** | 洋葱模型中间件管道 | 认证、限流、日志记录 |

```php
// app/hooks.php
return [
    'actions' => [
        'app.started' => [
            [App\Hooks\AppStarted::class, 'handle'],
        ],
    ],
    'filters' => [
        'request.data' => [
            [App\Filters\SanitizeInput::class, 'handle'],
        ],
    ],
    'pipes' => [
        'request.middleware' => [
            [App\Middlewares\CorsMiddleware::class, 'handle', 5],
            [App\Middlewares\AuthMiddleware::class, 'handle', 10],
        ],
    ],
];
```

### 6. 队列系统

支持 Redis 和 Database 两种驱动，提供异步任务处理能力：

```php
// 定义任务
class SendEmailJob extends \Restina\queue\Job
{
    public function __construct(array $data = [])
    {
        parent::__construct(self::class, $data);
    }

    public function handle(array $data): bool
    {
        // 发送邮件
        return true;
    }
}

// 分发任务
$this->queue->push(new SendEmailJob(['to' => 'user@example.com']));
$this->queue->later(60, new SendEmailJob(['to' => 'user@example.com'])); // 延迟 60 秒
```

启动消费者：

```bash
php restina queue:consume --queue=default --memory=256
```

### 7. 声明式定时任务

使用 `#[Scheduler]` 属性直接标注方法，无需额外配置：

```php
use Restina\attribute\Scheduler;

class CleanupTask
{
    #[Scheduler(cron: '0 2 * * *', name: 'cleanup.logs', desc: '每天凌晨2点清理日志')]
    public function cleanupLogs(): void
    {
        // 清理逻辑
    }
}
```

也可以在 `app/scheduler.php` 中以数组形式声明。

### 8. FrankenPHP Worker 模式

Restina 原生支持 FrankenPHP Worker 模式，绕过传统的 PHP-FPM 请求生命周期，实现常驻内存运行，性能提升显著。框架会自动检测运行环境并切换模式，无需修改任何代码。

```bash
# 开发环境：内置服务器
php restina run

# 生产环境：FrankenPHP Worker 模式
frankenphp run
```

---

## 架构设计

Restina 的架构可以用一句话概括：**注解驱动路由 + DI 容器 + 钩子管道**。

```
                    ┌─────────────────────────────────────┐
                    │           public/index.php           │
                    │    App::init()->boot()->run()->end() │
                    └──────────────┬──────────────────────┘
                                   │
                    ┌──────────────▼──────────────────────┐
                    │            App (单例)                │
                    │  ┌───────────────────────────────┐  │
                    │  │  boot() 阶段                   │  │
                    │  │  ├─ 加载配置                    │  │
                    │  │  ├─ 加载 Hook                   │  │
                    │  │  ├─ 注册核心服务到容器            │  │
                    │  │  │  (Config/Cache/Db/Jwt/...)   │  │
                    │  │  └─ 启动服务                     │  │
                    │  ├───────────────────────────────┤  │
                    │  │  run() 阶段                    │  │
                    │  │  ├─ 扫描注解 → 构建路由 Trie 树   │  │
                    │  │  ├─ 触发 app.started            │  │
                    │  │  └─ 按模式分发                   │  │
                    │  │     ├─ cgi  → handleRequest     │  │
                    │  │     ├─ cli  → Console           │  │
                    │  │     └─ worker→ FrankenPHP loop  │  │
                    │  └───────────────────────────────┘  │
                    └─────────────────────────────────────┘
                                   │
                    ┌──────────────▼──────────────────────┐
                    │         请求处理链                    │
                    │  Hook::runPipe('request.middleware') │
                    │    → 中间件管道（洋葱模型）             │
                    │      → Router::dispatch()           │
                    │        → Trie 树匹配路由              │
                    │          → 参数绑定 + 校验            │
                    │            → 控制器方法执行            │
                    │              → Response              │
                    └─────────────────────────────────────┘
```

核心组件全部以单例形式注册在 DI 容器中，控制器通过构造函数或 `#[Inject]` 获取依赖。整个请求处理链被 Hook 管道包裹，可以在任意节点插入中间件。

---

## 快速开始

### 安装

```bash
composer create-project ivupcn/restina my-api
cd my-api
```

### 写一个接口

```php
<?php
namespace App\Controllers;

use Restina\attribute\Route;
use Restina\attribute\Params;
use Restina\attribute\enum\FieldType;

class HelloController
{
    #[Route(methods: ['GET'], path: '/hello', jwt: false, permission: false)]
    #[Params(field: 'name', title: '姓名', type: FieldType::STRING, rules: 'required|lengthMax:50')]
    public function hello(string $name): array
    {
        return [
            'code'    => 0,
            'message' => "Hello, {$name}!",
        ];
    }
}
```

### 启动

```bash
php restina run
```

访问 `http://localhost:8000/hello?name=Restina`，搞定。

### 验证

```bash
composer test       # 运行测试
composer cs-check   # 代码风格检查
composer cs-fix     # 自动修复
```

---

## 技术栈

| 组件 | 选型 |
|------|------|
| 运行环境 | PHP 8.4+（严格模式） |
| 依赖注入 | 自研容器 Restina\Container（零第三方 DI 依赖） |
| HTTP 消息 | PSR-7 (nyholm/psr7) |
| 日志 | PSR-3 |
| ORM | Illuminate Database ^12.49 |
| JWT | firebase/php-jwt ^7.0 |
| 测试 | PHPUnit ^9.0 |
| 代码规范 | PSR-2 + phpcs |

---

## 适合什么场景？

Restina 最适合这些场景：

- **REST API 项目**：注解驱动 + 自动文档，天然为 API 而生
- **中小型项目**：不想引入 Laravel 全家桶，但又不想从零拼凑
- **微服务**：足够轻量，启动快，资源占用低
- **对性能有要求的场景**：FrankenPHP Worker 模式常驻内存，Trie 树路由匹配 O(k) 复杂度

不太适合的场景：

- 需要完整 MVC + 模板渲染的传统 Web 项目（Restina 专注 API）
- 需要 Laravel 那样丰富的生态和第三方包集成

---

## 项目状态

Restina 目前已在 GitHub 开源，遵循 Apache 2.0 协议。

- GitHub: [ivupcn/restina-framework](https://github.com/ivupcn/restina-framework)
- Packagist: `composer require ivupcn/restina`
- 运行环境要求：PHP 8.4+

欢迎 Star、Issue 和 PR。如果你也在寻找一个"刚刚好"的 PHP API 框架，Restina 值得一试。

---

*写 Restina 的初衷是相信一件事：好的框架不是给你更多选择，而是帮你减少不必要的决定。当你写下一个注解就知道它会生效，当文档随着代码自动更新，当依赖注入不需要任何配置——这些"不需要思考"的时刻，就是框架价值的体现。*
