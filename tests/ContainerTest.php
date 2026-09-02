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
        // PHP-DI 可以自动解析类类型依赖，无需显式注册
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

    // ─── getRawContainer ─────────────────────────────────────

    public function testGetRawContainer(): void
    {
        $raw = $this->container->getRawContainer();
        $this->assertInstanceOf(\DI\Container::class, $raw);
    }

    // ─── 自定义容器注入 ──────────────────────────────────────

    public function testConstructWithCustomContainer(): void
    {
        $diContainer = new \DI\Container();
        $diContainer->set('custom', 'value');

        $container = new Container($diContainer);
        $this->assertSame('value', $container->get('custom'));
    }
}
