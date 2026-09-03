<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Restina\Container;
use Restina\attribute\Inject;

// ─── 测试用桩类 ──────────────────────────────────────────────

class StubServiceA
{
    public string $name = 'serviceA';
}

class StubServiceB
{
    public StubServiceA $a;

    public function __construct(StubServiceA $a)
    {
        $this->a = $a;
    }
}

class StubWithDefaultParam
{
    public string $label;

    public function __construct(string $label = 'default')
    {
        $this->label = $label;
    }
}

class StubWithInjectProperty
{
    #[Inject]
    public StubServiceA $service;
}

class StubWithInjectExplicitId
{
    #[Inject('myService')]
    public StubServiceA $service;
}

class StubWithUnresolvableInject
{
    #[Inject]
    public string $value;
}

class StubNoConstructor
{
    public int $x = 10;
}

class StubWithUnresolvableScalar
{
    public function __construct(public string $required)
    {
    }
}

/**
 * 可选依赖桩类：依赖未注册的接口，参数可为 null 且有默认值
 */
interface StubUnregisteredInterface
{
}

class StubWithOptionalDependency
{
    public function __construct(public ?StubUnregisteredInterface $service = null)
    {
    }
}

/**
 * 依赖工厂服务的桩类：工厂闭包抛出 RuntimeException
 */
class StubDependsOnBrokenFactory
{
    public function __construct(public ?StubServiceA $service)
    {
    }
}

/**
 * 通过 make() 路径的循环依赖桩类：构造函数中再次 make 自身
 */
class StubSelfCircularViaMake
{
    public function __construct(Container $container)
    {
        $container->make(self::class);
    }
}

/**
 * get() 路径循环依赖桩类 A → B
 */
class StubCircularA
{
    public function __construct(StubCircularB $b)
    {
    }
}

/**
 * get() 路径循环依赖桩类 B → A
 */
class StubCircularB
{
    public function __construct(StubCircularA $a)
    {
    }
}

class ContainerTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    // ─── set / get / has ─────────────────────────────────────

    public function testSetAndGet(): void
    {
        $this->container->set('greeting', 'hello');
        $this->assertSame('hello', $this->container->get('greeting'));
    }

    public function testHasReturnsTrueForExisting(): void
    {
        $this->container->set('key', 'val');
        $this->assertTrue($this->container->has('key'));
    }

    public function testHasReturnsFalseForMissing(): void
    {
        $this->assertFalse($this->container->has('nonexistent'));
    }

    public function testSetCallableIsNotMarkedAsInstantiated(): void
    {
        $this->container->set('factory', fn() => 'created');
        $this->assertFalse($this->container->isInstantiated('factory'));
    }

    public function testSetInstanceIsMarkedAsInstantiated(): void
    {
        $obj = new StubServiceA();
        $this->container->set('svc', $obj);
        $this->assertTrue($this->container->isInstantiated('svc'));
    }

    public function testGetMarksAsInstantiated(): void
    {
        $this->container->set('val', 42);
        $this->container->get('val');
        $this->assertTrue($this->container->isInstantiated('val'));
    }

    // ─── make: 基本实例化 ────────────────────────────────────

    public function testMakeCreatesInstance(): void
    {
        $obj = $this->container->make(StubNoConstructor::class);
        $this->assertInstanceOf(StubNoConstructor::class, $obj);
        $this->assertSame(10, $obj->x);
    }

    public function testMakeMarksAsInstantiated(): void
    {
        $this->container->make(StubNoConstructor::class);
        $this->assertTrue($this->container->isInstantiated(StubNoConstructor::class));
    }

    // ─── make: 构造函数依赖自动解析 ──────────────────────────

    public function testMakeResolvesClassDependency(): void
    {
        // 先注册 StubServiceA 到容器
        $this->container->set(StubServiceA::class, new StubServiceA());

        $obj = $this->container->make(StubServiceB::class);
        $this->assertInstanceOf(StubServiceB::class, $obj);
        $this->assertInstanceOf(StubServiceA::class, $obj->a);
        $this->assertSame('serviceA', $obj->a->name);
    }

    public function testMakeUsesDefaultParamValue(): void
    {
        $obj = $this->container->make(StubWithDefaultParam::class);
        $this->assertSame('default', $obj->label);
    }

    public function testMakeWithExplicitParameters(): void
    {
        $obj = $this->container->make(StubWithDefaultParam::class, ['custom']);
        $this->assertSame('custom', $obj->label);
    }

    public function testMakeAutoWiresClassDependency(): void
    {
        // 容器可以自动解析类类型依赖，无需显式注册
        $obj = $this->container->make(StubServiceB::class);
        $this->assertInstanceOf(StubServiceB::class, $obj);
        $this->assertInstanceOf(StubServiceA::class, $obj->a);
    }

    public function testMakeThrowsForUnresolvableScalarParam(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // StubWithUnresolvableScalar 需要一个 string 参数，无默认值，容器无法自动解析
        $this->container->make(StubWithUnresolvableScalar::class);
    }

    // ─── injectProperties: #[Inject] 属性注入 ────────────────

    public function testInjectPropertyByTypeHint(): void
    {
        $this->container->set(StubServiceA::class, new StubServiceA());

        $obj = new StubWithInjectProperty();
        $this->container->injectProperties($obj);

        $this->assertInstanceOf(StubServiceA::class, $obj->service);
    }

    public function testInjectPropertyWithExplicitId(): void
    {
        $svc = new StubServiceA();
        $svc->name = 'custom';
        $this->container->set('myService', $svc);

        $obj = new StubWithInjectExplicitId();
        $this->container->injectProperties($obj);

        $this->assertSame('custom', $obj->service->name);
    }

    public function testInjectPropertyThrowsForBuiltinType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('无法自动解析');

        $obj = new StubWithUnresolvableInject();
        $this->container->injectProperties($obj);
    }

    // ─── 可选依赖降级 ────────────────────────────────────────

    public function testOptionalDependencyInjectsNullWhenNotRegistered(): void
    {
        // StubUnregisteredInterface 未注册且不可实例化，
        // 但参数可为 null —— 应注入 null 而不是报错
        $obj = $this->container->make(StubWithOptionalDependency::class);
        $this->assertNull($obj->service);
    }

    public function testOptionalDependencyResolvedWhenRegistered(): void
    {
        // 对照组：注册实现后正常注入
        $impl = new class implements StubUnregisteredInterface {};
        $this->container->set(StubUnregisteredInterface::class, $impl);
        $obj = $this->container->make(StubWithOptionalDependency::class);
        $this->assertInstanceOf(StubUnregisteredInterface::class, $obj->service);
    }

    public function testFactoryExceptionPropagatesInsteadOfInjectingNull(): void
    {
        // 工厂条目存在（has() 为 true）但解析时抛 RuntimeException
        // 异常必须穿透，不允许静默降级为 null
        $this->container->set(StubServiceA::class, function () {
            throw new \RuntimeException('工厂内部错误');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('工厂内部错误');

        $this->container->make(StubDependsOnBrokenFactory::class);
    }

    // ─── isInstantiated / clearInstantiated ──────────────────

    public function testClearInstantiated(): void
    {
        $this->container->set('svc', new StubServiceA());
        $this->assertTrue($this->container->isInstantiated('svc'));

        $this->container->clearInstantiated('svc');
        $this->assertFalse($this->container->isInstantiated('svc'));
    }

    public function testIsInstantiatedFalseByDefault(): void
    {
        $this->assertFalse($this->container->isInstantiated('anything'));
    }

    // ─── make() 循环依赖检测 ─────────────────────────────────

    public function testMakeDetectsCircularDependency(): void
    {
        // 注册自身到容器，使 make 时能解析 Container 依赖
        $this->container->set(Container::class, $this->container);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('make() 循环依赖');

        $this->container->make(StubSelfCircularViaMake::class);
    }

    public function testMakeCleansUpAfterException(): void
    {
        $this->container->set(Container::class, $this->container);

        try {
            $this->container->make(StubSelfCircularViaMake::class);
        } catch (\LogicException $e) {
            // 预期异常
        }

        // 清理后，make() 其他类应正常工作
        $obj = $this->container->make(StubNoConstructor::class);
        $this->assertInstanceOf(StubNoConstructor::class, $obj);
    }

    // ─── get() 路径循环依赖检测 ──────────────────────────────

    public function testGetDetectsCircularDependency(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('循环依赖');

        $this->container->make(StubCircularA::class);
    }

    // ─── 自动装配缓存 ────────────────────────────────────────

    public function testAutowireCachesResult(): void
    {
        $obj1 = $this->container->get(StubNoConstructor::class);
        $obj2 = $this->container->get(StubNoConstructor::class);
        $this->assertSame($obj1, $obj2);
    }

    // ─── 闭包工厂 ────────────────────────────────────────────

    public function testClosureFactoryCalledEachTime(): void
    {
        $count = 0;
        $this->container->set('counter', function () use (&$count) {
            return ++$count;
        });

        $this->assertSame(1, $this->container->get('counter'));
        $this->assertSame(2, $this->container->get('counter'));
    }

    // ─── has() 对可自动装配类返回 true ───────────────────────

    public function testHasReturnsTrueForAutowirableClass(): void
    {
        $this->assertTrue($this->container->has(StubNoConstructor::class));
    }

    public function testHasReturnsFalseForNonExistentClass(): void
    {
        $this->assertFalse($this->container->has('NonExistent\\FakeClass'));
    }

    // ─── get() 对不存在的类抛异常 ────────────────────────────

    public function testGetThrowsForNonExistentClass(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('找不到服务或类');
        $this->container->get('NonExistent\\FakeClass');
    }
}
