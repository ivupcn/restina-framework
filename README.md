# Restina Framework

一个基于 Slim 框架和 Eloquent ORM 构建的轻量级 PHP 框架，用于快速开发 API。

Restina是一个免费开源的，快速、简单的面向对象的轻量级PHP开发框架，是为了敏捷WEB应用开发和简化应用开发而诞生的。Restina秉承简洁实用的设计原则，在保持出色的性能和至简代码的同时，更注重易用性。遵循Apache2开源许可协议发布，意味着你可以免费使用Restina，甚至允许把你基于Restina开发的应用开源或商业产品发布/销售。

## 主要新特性

* 采用`PHP8`强类型（严格模式）
* 支持更多的`PSR`规范
* 系统服务注入支持
* ORM作为独立组件使用
* 全新的Hook系统
* 规范扩展接口
* 对IDE更加友好
* 统一和精简大量用法


> Restina的运行环境要求PHP8.0+。

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

启动服务

~~~
cd tp
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
│  ├─Commands           Cli控制器目录
│  ├─Controllers        控制器目录
│  ├─Filters            过滤器目录
│  ├─Hooks              Hook目录
│  ├─Models             模型目录
│  ├─Views              视图目录
│  ├─config.php         配置文件
│  └─hooks.php          钩子配置文件
│
├─public                WEB目录（对外访问目录）
│  ├─index.php          入口文件
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
* 注册中间件
* 启动控制器

## 入口文件

Restina采用单一入口模式进行项目部署和访问，一个应用都有一个统一（但不一定是唯一）的入口。

默认的应用入口文件位于public/index.php，默认内容如下：

~~~
<?php
// public/index.php
require_once __DIR__ . '/../vendor/autoload.php';
use Restina\App;
$app = App::init()->boot()->run();
~~~

> 如果你没有特殊的自定义需求，无需对入口文件做任何的更改。
> 入口文件位置的设计是为了让应用部署更安全，请尽量遵循public目录为唯一的web可访问目录，其他的文件都可以放到非WEB访问目录下面。

## URL访问

Restina的URL访问受路由影响。

框架扫描每个类的每个方法，如果方法标记了@route，将被自动添加为路由。

~~~
class DemoController
{
    /**
     * 获取用户列表
     * 
     * @route GET /demo/getUsers
     */
    public function getUsers(int $page = 1, int $limit = 10, string $sort = 'id', string $search = '')
}
~~~
以上代码表示 http 请求 GET /demo/getUsers 其实现为 DemoController:: getUsers, 其中{id}为url 的可变部分。

标注在类的注释里，用于指定 Controller 类中所定义的全部接口的uri 的 path。

语法：` @route <method> <uri>`

标注在方法的注释里，用于指定接口的路由。method为指定的 http 方法，可以是 GET、HEAD、POST、PUT、PATCH、DELETE、OPTION、DELETE。uri 中可以带变量，用{}包围。

## 参数绑定

实现接口时，通常需要从 http 请求中提取数据，作为方法的输入参数，并将方法的返回值转换成 http 的输出。参数绑定功能即可以帮你完成上述工作。

### 输入绑定

#### 根据方法定义绑定

默认情况下，框架会从http请求中提取和方法的参数名同名的变量，作为函数的参数。比如：

~~~
class DemoController
{
    /**
     * 获取用户列表
     * 
     * @route GET /demo/getUsers
     */
    public function getUsers(int $page = 1, int $limit = 10, string $sort = 'id', string $search = '')
}
~~~

上述代码，对应的 http 请求形式为 GET /demo/getUsers/?page=1&limit=10&sort=id&search=test。

#### @param

如果在方法的注释中，标注了 @param，就会有用 @param 的绑定信息覆盖默认来自函数定义的绑定信息。@param 可以指定变量的类型，而原函数定义中只能在参数是数组或者对象时才能指定类型。@param 的语法为标准 PHP Document 的语法。

~~~
/**
     * 获取用户列表
     * 
     * @route GET /demo/getUsers
     * @param int $page 页码
     * @param int $limit 分页大小
     * @param string $sort
     * @param string $search
     */
    public function getUsers(int $page = 1, int $limit = 10, string $sort = 'id', string $search = '')
~~~

以上代码，除了绑定变量外，还指定了变量类型，即如果输入值无法转换成 int，将返回 400 BadRequest 错误。未指定@param 时，参数的类型默认为 mixed。

如果想指定某个输入参数可选，只需给方法参数设置一个默认值。

默认情况下，函数的返回值将 jsonencode 后，作为 body 输出。

## 参数校验

在"参数绑定"时，起始已经支持了两项基本的校验（类型和是否必选），如果要支持更复杂的校验规则，可以通过 @v 指定，如：

~~~
/**
     * 获取用户列表
     * 
     * @route GET /demo/getUsers
     * @param int $page 页码 {@v min:1|integer|required}
     * @param int $limit 分页大小 {@v min:1|max:100|integer|required}
     * @param string $sort {@v in:id,name,email|optional}
     * @param string $search {@v lengthMax:50|optional}
     */
    public function getUsers(int $page = 1, int $limit = 10, string $sort = 'id', string $search = '')
~~~

### 语法

`@v <rule>[:param0[,param1...]][|<rule2>...]`

* 多个规则间用|分割。
* 规则和其参数间用:分割, 如果有多个参数，参数间用,分割。

### 支持的规则

* equired - Required field
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
    /**
     * @param LoggerInterface $logger 通过依赖注入传入
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger;
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

Restina 项目可以很方便的生成 Swagger 文档，无需添加额外的 Annotation（很多框架为支持 Swagger，通常需要增加很多额外的注释，而这些注释只用于 Swagger。Restina 生成 Swagger 的信息来自路由的标准注释，包括@route, @param, @return，@throws 等）。

只需访问你的项目 url+/swagger如( http://localhost/swagger)，即可获取 json 格式的 Swagger 文档。


## 参与开发

请参阅 [Restina核心框架包](https://github.com/ivupcn/restina-framework)。

## 版权信息

Restina遵循Apache2开源协议发布，并提供免费使用。

本项目包含的第三方源码和二进制文件之版权信息另行标注。

版权所有Copyright © 2006-2019 by ivup.cn (http://ivup.cn)

All rights reserved。

Restina® 商标和著作权所有者为ivup.cn。

更多细节参阅 [LICENSE](LICENSE)
