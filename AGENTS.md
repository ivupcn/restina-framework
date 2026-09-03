# AGENTS.md — Restina Framework

## 导航索引

| 任务 | 参考章节 | 关键文件 |
|------|----------|----------|
| 添加新 API 路由 | [工作流：添加新路由](#工作流添加新路由) | `src/attribute/Route.php`, `src/attribute/Params.php` |
| 创建新命令 | [工作流：创建新命令](#工作流创建新命令) | `src/console/Command.php`, `src/console/CommandRegistry.php` |
| 创建队列任务 | [工作流：创建队列任务](#工作流创建队列任务) | `src/queue/Job.php`, `src/queue/QueueInterface.php` |
| 创建定时任务 | [工作流：创建定时任务](#工作流创建定时任务) | `src/attribute/Scheduler.php`, `src/Scheduler.php` |
| 添加参数校验 | [关键约定 → 参数校验规则](#参数校验规则) | `src/Validator.php` |
| 配置依赖注入 | [关键约定 → 依赖注入](#依赖注入) | `src/Container.php`, `src/attribute/Inject.php` |
| 运行测试 | [验证命令](#验证命令) | `phpunit.xml`, `tests/` |

---

## 项目概述

Restina 是一个基于 PHP 8.4+ 的轻量级 REST API 框架，采用注解驱动路由、自研轻量级依赖注入容器，支持 API 文档自动生成、队列和定时任务。

## 模块结构

### `src/attribute/` — 属性（注解）定义

PHP 8 Attribute 类，用于声明式路由、参数校验和文档生成：

| 属性 | 职责 |
|------|------|
| `Route` | 声明 HTTP 方法、路径、JWT/权限控制 |
| `Params` | 声明接口参数（字段、类型、校验规则），可重复 |
| `Inject` | 属性注入标记，支持按类型推断或指定 ID |
| `Api` / `Docs` | API 文档元数据 |
| `Headers` / `Returns` | 请求头与响应声明 |
| `Scheduler` | 定时任务标记 |

### `src/console/` — 命令行系统

- `Command` — 命令抽象基类，定义 `configure()` + `handle(App)` 生命周期
- `CommandRegistry` — 命令注册与发现
- 内置命令：`HelpCommand`、`ListCommand`、`SchedulerCommand`

### `src/queue/` — 队列系统

- `QueueInterface` — 队列驱动接口（push / pop / later / retry）
- `Job` — 任务封装，支持类名或 Closure 回调
- `Message` — 队列消息体
- 驱动：`driver/Redis`、`driver/Database`
- 命令：`command/ConsumeCommand`（消费）、`command/QueueCommand`（管理）

### 核心文件（`src/`）

| 文件 | 职责 |
|------|------|
| `App` | 应用入口，引导启动流程 |
| `Router` | Trie 树路由，支持动态参数 `{param}` |
| `Container` | 自研轻量级 DI 容器：`set`/`get`/`has`、闭包工厂、反射自动装配（带缓存）、`#[Inject]` 属性注入、`get` 与 `make` 双路径循环依赖检测 |
| `Request` / `Response` | PSR-7 请求响应封装 |
| `Hook` | Hook / 中间件管道系统 |
| `Model` / `Db` | 基于 Illuminate Database 的 ORM |
| `Config` / `Cache` / `Redis` / `Logger` | 基础服务 |
| `Jwt` | JWT 认证 |
| `Validator` | 参数校验 |
| `Scheduler` / `Cron` | 定时任务调度 |

## 常用工作流

### 工作流：添加新路由

1. 在 `app/controllers/` 下创建或编辑控制器类
2. 使用 `#[Route]` 声明路由（方法、路径、JWT、权限）
3. 使用 `#[Params]` 声明每个参数（字段、类型、校验规则）
4. 方法返回值类型为 `array`，框架自动 JSON 编码输出

```php
<?php
namespace App\Controllers;

use Restina\attribute\Route;
use Restina\attribute\Params;
use Restina\attribute\enum\FieldType;

class OrderController
{
    #[Route(methods: ['POST'], path: '/orders', code: 'order.create', jwt: true)]
    #[Params(field: 'product_id', title: '商品ID', type: FieldType::INTEGER, rules: 'required|integer')]
    #[Params(field: 'quantity', title: '数量', type: FieldType::INTEGER, rules: 'required|min:1')]
    public function create(): array
    {
        // 业务逻辑
        return ['code' => 0, 'message' => '创建成功'];
    }
}
```

**检查清单：**
- [ ] `#[Route]` 的 `path` 不与已有路由冲突
- [ ] 每个参数都有对应的 `#[Params]` 声明
- [ ] 需要认证的接口 `jwt` 保持 `true`（默认值）
- [ ] 公开接口显式设置 `jwt: false, permission: false`
- [ ] 运行 `composer test` 确认无回归

### 工作流：创建新命令

1. 在 `app/commands/` 下创建新类，继承 `Restina\console\Command`
2. 实现 `configure()` 设置 `$this->signature` 和 `$this->description`
3. 实现 `handle(App $app): int` 编写业务逻辑
4. 使用 `$this->argument('name')` 获取参数，`$this->option('name')` 获取选项
5. 使用 `$this->success()` / `$this->error()` / `$this->info()` 输出信息

```php
<?php
namespace App\Commands;

use Restina\App;
use Restina\console\Command;

class MigrateCommand extends Command
{
    protected string $signature = 'migrate';
    protected string $description = '执行数据库迁移';

    protected function configure(): void
    {
        $this->signature = 'migrate {--force : 强制执行}';
    }

    public function handle(App $app): int
    {
        if ($this->hasOption('force')) {
            $this->info('强制模式已启用');
        }
        // 迁移逻辑...
        $this->success('迁移完成');
        return 0;
    }
}
```

**检查清单：**
- [ ] 类继承 `Restina\console\Command`
- [ ] `configure()` 和 `handle()` 两个抽象方法均已实现
- [ ] `$signature` 格式正确（命令名 + 可选参数定义）
- [ ] `handle()` 返回 `int`（0 表示成功）
- [ ] 运行 `php restina list` 确认命令已注册

### 工作流：创建队列任务

1. 在 `app/job/` 下创建任务类
2. 通过 `Queue::push()` 或 `Queue::later()` 投递
3. 启动消费者：`php restina queue:work`

```php
<?php
namespace App\Jobs;

use Restina\queue\Job;

class SendEmailJob extends Job
{
    public function __construct(array $data = [])
    {
        parent::__construct(self::class, $data);
    }

    public function handle(array $data): bool
    {
        // 发送邮件逻辑
        return true;
    }
}
```

### 工作流：创建定时任务

1. 在控制器或任意类中定义方法，使用 `#[Scheduler]` 标注
2. 指定 `cron` 表达式、`name` 和可选 `desc`

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

## 关键约定

### 属性路由

```php
#[Route(methods: ['GET'], path: '/users/{id}', jwt: true)]
#[Params(field: 'id', type: 'int', rules: 'required')]
public function getUser(Request $request): array { ... }
```

- 路由参数使用 `{paramName}` 语法，可选正则约束 `{id:[0-9]+}`
- `#[Route]` 标注在方法上，`#[Params]` 可重复声明多个参数

### 参数校验规则

通过 `#[Params(rules: '')]` 指定，多规则用 `|` 分割：

| 规则 | 说明 |
|------|------|
| `required` | 必填 |
| `integer` / `numeric` / `boolean` / `array` | 类型校验 |
| `min:N` / `max:N` | 数值范围 |
| `lengthMin:N` / `lengthMax:N` / `lengthBetween:N,M` | 字符串长度 |
| `in:a,b,c` / `notIn:a,b,c` | 枚举校验 |
| `email` / `url` / `ip` | 格式校验 |
| `date` / `dateFormat:fmt` / `dateBefore:d` / `dateAfter:d` | 日期校验 |
| `regex:pattern` | 正则匹配 |
| `optional` | 可选字段（存在时仍需通过校验） |
| `equals:field` / `different:field` | 字段对比 |
| `contains:str` | 包含检查 |

### 依赖注入

- 构造函数注入：容器自动按类型解析
- 属性注入：使用 `#[Inject]` 标注 public/protected/private 属性
- `Container::make()` 自动反射创建实例并注入依赖

### 命名规范

- 命名空间：`Restina\` 对应 `src/`（PSR-4）
- 类名：PascalCase（如 `UserController`）
- 方法名：camelCase
- 属性文件统一放在 `src/attribute/` 目录，小写目录名

### 命令扩展

继承 `Restina\console\Command`，实现 `configure()` 和 `handle(App)` 方法。

### 队列任务

实现 `Restina\queue\Job`，通过 `QueueInterface` 驱动投递和消费。

## 验证命令

```bash
# 运行测试
composer test

# 代码风格检查（PSR-2）
composer cs-check

# 自动修复代码风格
composer cs-fix
```
