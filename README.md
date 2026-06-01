# Restina Framework

一个轻量级 PHP 框架，用于快速开发 API。

Restina是一个免费开源的，快速、简单的面向对象的轻量级PHP开发框架，是为了敏捷WEB应用开发和简化应用开发而诞生的。Restina秉承简洁实用的设计原则，在保持出色的性能和至简代码的同时，更注重易用性。遵循Apache2开源许可协议发布，意味着你可以免费使用Restina，甚至允许把你基于Restina开发的应用开源或商业产品发布/销售。

## 主要新特性

* 原生支持`PHP8.4+`强类型（严格模式）
* 支持更多的`PSR`规范
* 系统服务注入支持
* ORM作为独立组件使用
* 全新的Hook系统
* 规范扩展接口
* 对IDE更加友好
* 支持 FrankenPHP Worker 模式
* 统一和精简大量用法


> Restina的运行环境要求PHP8.4+。

## 安装

~~~
composer require ivupcn/restina
~~~

配置 PSR-4 自动加载

安装完成后，需要在项目的 [composer.json](composer.json) 文件中添加 PSR-4 命名空间映射配置：

~~~
{
    "autoload": {
        "psr-4": {
            "App\\": "app/"
        }
    }
}
~~~

然后运行以下命令重新生成自动加载文件：

~~~
composer dump-autoload
~~~

使用 Nginx ，修改你的项目对应的配置：

~~~
location / {
    try_files $uri /index.php$is_args$args;
}
~~~

启动服务

~~~
cd /path/to/project
php restina run
~~~

然后就可以在浏览器中访问

~~~
http://localhost:8000
~~~

如果需要更新框架使用
~~~
composer update ivupcn/restina
~~~

## 目录结构

~~~
www  WEB部署目录（或者子目录）
├─app           应用目录
│  ├─commands           Cli控制器目录
│  ├─controllers        控制器目录
│  ├─filters            过滤器目录
│  ├─hooks              Hook目录
│  ├─job                队列任务目录
│  ├─middlewares        中间件目录
│  ├─models             模型目录
│  ├─scheduler          定时任务目录
│  ├─views              视图目录
│  ├─config.php         应用配置文件
│  ├─scheduler.php      定时任务配置文件
│  └─hooks.php          钩子配置文件
│
├─public                WEB目录（对外访问目录）
│  └─index.php          入口文件
│
├─extend                扩展类库目录
├─runtime               应用的运行时目录（可写，可定制）
├─vendor                Composer类库目录
├─composer.json         composer 定义文件
├─LICENSE               授权说明文件
├─README.md             README 文件
~~~

## 命名规范

`Restina`遵循PSR-2命名规范和PSR-4自动加载规范。

### 目录和文件

* 目录使用小写+下划线；
* 类库、函数文件统一以.php为后缀；
* 类的文件名均以命名空间定义，并且命名空间的路径和类库文件所在路径一致；
* 类（包含接口和Trait）文件采用驼峰法命名（首字母大写），其它文件采用小写+下划线命名；
* 类名（包括接口和Trait）和文件名保持一致，统一采用驼峰法命名（首字母大写）；

### 函数和类、属性命名

* 类的命名采用驼峰法（首字母大写），例如 User、UserType；
* 函数的命名使用小写字母和下划线（小写字母开头）的方式，例如 get_client_ip；
* 方法的命名使用驼峰法（首字母小写），例如 getUserName；
* 属性的命名使用驼峰法（首字母小写），例如 tableName、instance；
* 特例：以双下划线__打头的函数或方法作为魔术方法，例如 __call 和 __autoload；

### 常量和配置

* 常量以大写字母和下划线命名，例如 APP_PATH；
* 配置参数以小写字母和下划线命名，例如 url_route_on 和url_convert；
* 环境变量定义使用大写字母和下划线命名，例如APP_DEBUG；

### 数据表和字段

* 数据表和字段采用小写加下划线方式命名，并注意字段名不要以下划线开头，例如 restina_user 表和 restina_name字段，不建议使用驼峰和中文作为数据表及字段命名。

## 请求流程

* 载入Composer的自动加载autoload文件
* 实例化系统应用基础类Restina\App
* 获取应用目录等相关路径信息
* 加载应用配置
* 设置运行环境
* 载入Hook配置
* 注册核心服务
* 注册自定义服务
* 启动服务
* 注册控制器
* 启动控制器

## 入口文件

Restina采用单一入口模式进行项目部署和访问，默认的应用入口文件位于public/index.php：

~~~
<?php
// public/index.php
require_once __DIR__ . '/../vendor/autoload.php';
use Restina\App;
App::init()->boot()->run()->end();
~~~

> 如果你没有特殊的自定义需求，无需对入口文件做任何的更改。
> 如果需要自定义入口行为，可以在调用`App::init()`之前添加预处理逻辑。
> 入口文件位置的设计是为了让应用部署更安全，请尽量遵循public目录为唯一的web可访问目录，其他的文件都可以放到非WEB访问目录下面。

## URL访问

Restina的URL访问受路由影响。

框架扫描每个类的每个方法注解，如果方法标记了 #[Route()]，将被自动添加为路由。

~~~
class DemoController
{
     #[Route(methods: ['GET'], path: '/demo/getUsers', code: 'demo.getUsers', permission: false, jwt: false, autoRefreshToken: true)]
    public function getUsers(int $page = 1, int $limit = 10, string $sort = 'id', string $search = '')
}
~~~
以上代码表示 http 请求 GET /demo/getUsers 其实现为 DemoController:: getUsers, 其中{id}为url 的可变部分。

标注在类的注释里，用于指定 Controller 类中所定义的全部接口的uri 的 path。

语法：` #[Route(methods: <method>, path: <path>, code: <code>, permission: <permission>, jwt: <jwt>, autoRefreshToken: <autoRefreshToken>)]`

标注在方法的注释里，用于指定接口的路由。methods为指定的 http 方法，可以是 GET、HEAD、POST、PUT、PATCH、DELETE、OPTION、DELETE。uri 中可以带变量，用{}包围。

## 参数绑定

实现接口时，通常需要从 http 请求中提取数据，作为方法的输入参数，并将方法的返回值转换成 http 的输出。参数绑定功能即可以帮你完成上述工作。

### 输入绑定

#### 根据方法定义绑定

默认情况下，框架会从http请求中提取和方法的参数名同名的变量，作为函数的参数。比如：

~~~
class DemoController
{
    #[Route(methods: ['GET'], path: '/demo/getUsers', code: 'demo.getUsers', permission: false, jwt: false, autoRefreshToken: true)]
    public function getUsers(int $page = 1, int $limit = 10, string $sort = 'id', string $search = '')
}
~~~

上述代码，对应的 http 请求形式为 GET /demo/getUsers/?page=1&limit=10&sort=id&search=test。

#### Params

~~~
     #[Route(methods: ['GET'], path: '/demo/getUsers', code: 'demo.getUsers', permission: false, jwt: false, autoRefreshToken: true)]
     #[Params(field: 'page', title: '页码', type: FieldType::INTEGER)]
     #[Params(field: 'limit', title: '分页大小', type: FieldType::INTEGER)]
     #[Params(field: 'sort', title: '排序字段', type: FieldType::STRING)]
     #[Params(field: 'search', title: '搜索内容', type: FieldType::STRING)]
    public function getUsers(int $page = 1, int $limit = 10, string $sort = 'id', string $search = '')
~~~

以上代码，除了绑定变量外，还指定了变量类型，即如果输入值无法转换成 int，将返回 400 BadRequest 错误。未指定@param 时，参数的类型默认为 mixed。

如果想指定某个输入参数可选，只需给方法参数设置一个默认值。

默认情况下，函数的返回值将 jsonencode 后，作为 body 输出。

## 参数校验

在"参数绑定"时，起始已经支持了两项基本的校验（类型和是否必选），如果要支持更复杂的校验规则，可以通过 @v 指定，如：

~~~
/**
     #[Route(methods: ['GET'], path: '/demo/getUsers', code: 'demo.getUsers', permission: false, jwt: false, autoRefreshToken: true)]
     #[Params(field: 'page', title: '页码', type: FieldType::INTEGER, rules: 'min:1|integer|required')]
     #[Params(field: 'limit', title: '分页大小', type: FieldType::INTEGER, rules: 'min:1|max:100|integer|required')]
     #[Params(field: 'sort', title: '排序字段', type: FieldType::STRING, rules: 'in:id,name,email|optional')]
     #[Params(field: 'search', title: '搜索内容', type: FieldType::STRING, rules: 'lengthMax:50|optional')]
    public function getUsers(int $page = 1, int $limit = 10, string $sort = 'id', string $search = '')
~~~

### 语法

`rules=[:param0[,param1...]][|<rule2>...]`

* 多个规则间用|分割。
* 规则和其参数间用:分割, 如果有多个参数，参数间用,分割。

### 支持的规则

* required - Required field
* equals - Field must match another field (email/password confirmation)
* different - Field must be different than another field
* accepted - Checkbox or Radio must be accepted (yes, on, 1, true)
* numeric - Must be numeric
* integer - Must be integer number
* boolean - Must be boolean
* array - Must be array
* length - String must be certain length
* lengthBetween - String must be between given lengths
* lengthMin - String must be greater than given length
* lengthMax - String must be less than given length
* min - Minimum
* max - Maximum
* in - Performs in_array check on given array values
* notIn - Negation of in rule (not in array of values)
* ip - Valid IP address
* email - Valid email address
* url - Valid URL
* urlActive - Valid URL with active DNS record
* alpha - Alphabetic characters only
* alphaNum - Alphabetic and numeric characters only
* slug - URL slug characters (a-z, 0-9, -, _)
* regex - Field matches given regex pattern
* date - Field is a valid date
* dateFormat - Field is a valid date in the given format
* dateBefore - Field is a valid date and is before the given date
* dateAfter - Field is a valid date and is after the given date
* contains - Field is a string and contains the given string
* creditCard - Field is a valid credit card number
* optional - Value does not need to be included in data array. If it is however, it must pass validation.

## 依赖注入

Restina 使用开源项目 PHP-DI 作为依赖注入的基础实现。

### 构造函数注入

~~~
class DemoController
{
    private LoggerInterface $logger;
    
    /**
     * @param LoggerInterface $logger 通过依赖注入传入
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;  // 修正：添加赋值语句
    }
    ...
}
~~~

### 属性注入

~~~
class DemoController
{
    /**
     * @inject 
     */
    private \restina\Db $db;
}
~~~

Restina 通过注释@inject标记注入依赖

## 文档输出

Restina 项目可以很方便的生成 Swagger 文档，无需添加额外的 Annotation（很多框架为支持 Swagger，通常需要增加很多额外的注释，而这些注释只用于 Swagger。Restina 生成 Swagger 的信息来自路由的注解，包括route, param, return，throws 等）。

只需访问你的项目 url+/swagger如( http://localhost/swagger)，即可获取 json 格式的 Swagger 文档。

## 错误处理

Restina 提供了完善的错误处理机制：

~~~
// 在配置文件中启用调试模式
'app' => [
    'debug' => true,  // 开发环境设为true，生产环境设为false
],
~~~

框架会自动捕获异常并记录到日志中。

## 队列功能

### 配置队列连接

在应用配置文件 `app/config.php` 中添加队列配置：

~~~
'queue' => [
    'default' => 'redis',
    'connections' => [
        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => 'default',
            'retry_after' => 90,
            'block_for' => null,
        ],
        'database' => [
            'driver' => 'database',
            'table' => 'queue_jobs',
            'failed_table' => 'queue_failed_jobs',
            'retry_after' => 90,
            'after_commit' => false
        ],
        'sync' => [
            'driver' => 'sync',  // 同步队列，用于开发环境
        ],
    ],
],
~~~

### 创建队列任务

创建队列任务类需要继承框架提供的基础任务类：

~~~
<?php
namespace App\Jobs;

use Restina\Queue\Job;

class SendEmailJob extends Job
{
    protected $data;
    
    public function __construct($data)
    {
        $this->data = $data;
    }
    
    public function handle()
    {
        // 执行任务逻辑
        // 发送邮件等操作
    }
}
~~~

### 分发队列任务

~~~
use App\Jobs\SendEmailJob;
use Restina\Queue\Queue;

// 分发任务到队列
Queue::push(new SendEmailJob($data));

// 延迟执行
Queue::later(60, new SendEmailJob($data)); // 60秒后执行
~~~

### 启动队列处理器

在命令行中启动队列处理器：

`php restina queue:work --queue=default --max-time=3600`

更多选项：
- `--queue=high,default`: 指定多个队列
- `--delay=3`: 设置延迟时间
- `--tries=3`: 设置最大尝试次数
- `--timeout=60`: 设置任务超时时间

## 定时任务（Scheduler）

### 定时任务配置

定时任务配置文件位于 `app/scheduler.php`：

~~~
<?php
// app/scheduler.php

use Restina\Scheduler\Schedule;

return function (Schedule $schedule) {
    // 每分钟执行一次清理任务
    $schedule->command('cleanup:logs')->everyMinute();
    
    // 每小时执行一次统计任务
    $schedule->command('stats:generate')->hourly();
    
    // 每天凌晨2点执行备份任务
    $schedule->command('backup:database')->dailyAt('02:00');
    
    // 每周日凌晨3点执行维护任务
    $schedule->command('maintenance:run')->weeklyOn(0, '03:00');
    
    // 自定义 Cron 表达式
    $schedule->command('custom:task')->cron('0 22 * * 1-5'); // 工作日晚上10点执行
};
~~~

### 定时任务命令

在 `app/commands` 目录下创建定时任务命令类：

~~~
<?php
// app/commands/CleanupLogsCommand.php

namespace App\Commands;

use Restina\Console\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CleanupLogsCommand extends Command
{
    protected static $defaultName = 'cleanup:logs';
    
    protected function configure()
    {
        $this->setDescription('清理过期日志文件');
    }
    
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // 清理逻辑
        $output->writeln('开始清理日志...');
        
        // 实际清理代码
        
        $output->writeln('日志清理完成！');
        return 0;
    }
}
~~~

### 启动定时任务调度器

确保系统的 crontab 中添加以下条目，使调度器每分钟运行一次：

`* * * * * cd /path-to-your-project && php restina schedule:run >> /dev/null 2>&1`

然后启动调度器监听：

`php restina schedule:listen`

定时任务常用方法

|方法|说明|
|-----|----|
|->everyMinute()|每分钟执行一次|
|->everyFiveMinutes()|每五分钟执行一次|
|->everyTenMinutes()|每十分钟执行一次|
|->everyFifteenMinutes()|每十五分钟执行一次|
|->everyThirtyMinutes()|每三十分钟执行一次|
|->hourly()|每小时执行一次|
|->hourlyAt(17)|每小时17分执行一次|
|->daily()|每天凌晨执行|
|->dailyAt('08:00')|每天08:00执行|
|->twiceDaily(1, 13)|每天1:00和13:00执行|
|->weekly()|每周日凌晨执行|
|->weeklyOn(1, '08:00')|每周1凌晨08:00执行|
|->monthly()|每月1号凌晨执行|
|->monthlyOn(15, '08:00')|每月15号凌晨08:00执行|
|->quarterly()|每季度1号凌晨执行|
|->yearly()|每年1月1日凌晨执行|

### 定时任务约束条件

~~~
$schedule->command('backup:database')
    ->daily()
    ->when(function () {
        return date('d') === '01'; // 每月1号执行
    });

// 在特定环境中运行
$schedule->command('send:report')
    ->daily()
    ->environments('production');

// 只在特定服务器运行
$schedule->command('deploy:worker')
    ->everyMinute()
    ->onOneServer();
~~~

### 自定义 Cron 表达式

~~~
$schedule->command('custom:task')->cron('0 22 * * 1-5'); // 工作日晚上10点执行
$schedule->command('weekly:report')->cron('0 0 * * 0'); // 每周日凌晨执行
~~~

### 队列与定时任务结合使用

~~~
// 在定时任务中分发队列任务
$schedule->call(function () {
    // 查询待处理的数据
    $pendingItems = DB::table('items')->where('status', 'pending')->get();
    
    foreach ($pendingItems as $item) {
        Queue::push(new ProcessItemJob($item));
    }
})->everyFiveMinutes();
~~~

## 参与开发

请参阅 [Restina核心框架包](https://github.com/ivupcn/restina-framework)。

## 版权信息

Restina遵循Apache2开源协议发布，并提供免费使用。

本项目包含的第三方源码和二进制文件之版权信息另行标注。

版权所有Copyright © 2006-2019 by ivup.cn (http://ivup.cn)

All rights reserved。

Restina® 商标和著作权所有者为ivup.cn。

更多细节参阅 [LICENSE](LICENSE)
