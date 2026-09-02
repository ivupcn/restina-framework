<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Restina\App;
use Restina\Container;

class AppTest extends TestCase
{
    protected function setUp(): void
    {
        App::resetInstance();
    }

    protected function tearDown(): void
    {
        App::resetInstance();
    }

    // ─── 单例行为 ────────────────────────────────────────────

    public function testAppInitialization()
    {
        $app = App::init();

        $this->assertInstanceOf(App::class, $app);
    }

    public function testAppIsSingleton()
    {
        $app1 = App::init();
        $app2 = App::init();

        $this->assertSame($app1, $app2);
    }

    public function testGetInstanceReturnsNullBeforeInit(): void
    {
        $this->assertNull(App::getInstance());
    }

    public function testGetInstanceReturnsSameAfterInit(): void
    {
        $app = App::init();
        $this->assertSame($app, App::getInstance());
    }

    public function testResetInstanceClearsSingleton(): void
    {
        $app1 = App::init();
        App::resetInstance();
        $app2 = App::init();

        $this->assertNotSame($app1, $app2);
    }

    public function testResetInstanceMakesGetInstanceReturnNull(): void
    {
        App::init();
        App::resetInstance();

        $this->assertNull(App::getInstance());
    }

    // ─── 路径获取器 ──────────────────────────────────────────

    public function testGetRootPathReturnsString(): void
    {
        $app = App::init();
        $this->assertIsString($app->getRootPath());
    }

    public function testGetAppPathReturnsString(): void
    {
        $app = App::init();
        $this->assertIsString($app->getAppPath());
    }

    public function testGetViewPathReturnsString(): void
    {
        $app = App::init();
        $this->assertIsString($app->getViewPath());
    }

    public function testGetCachePathReturnsString(): void
    {
        $app = App::init();
        $this->assertIsString($app->getCachePath());
    }

    public function testAppPathIsUnderRootPath(): void
    {
        $app = App::init();
        $this->assertStringStartsWith($app->getRootPath(), $app->getAppPath());
    }

    public function testViewPathIsUnderAppPath(): void
    {
        $app = App::init();
        $this->assertStringStartsWith($app->getAppPath(), $app->getViewPath());
    }

    // ─── 状态查询 ────────────────────────────────────────────

    public function testIsDebugModeNotInitializedBeforeBoot(): void
    {
        $app = App::init();
        $ref = new \ReflectionProperty(App::class, 'isDebugMode');
        $this->assertFalse($ref->isInitialized($app));
    }

    public function testIsRegisteredFalseBeforeBoot(): void
    {
        $app = App::init();
        $this->assertFalse($app->isRegistered());
    }

    public function testIsBootstrappedFalseBeforeBoot(): void
    {
        $app = App::init();
        $this->assertFalse($app->isBootstrapped());
    }

    // ─── 容器操作 (bind / resolve / make) ────────────────────

    public function testBindAndResolve(): void
    {
        $app = App::init();
        $this->injectContainer($app);

        $app->bind('test.service', new \stdClass());
        $resolved = $app->resolve('test.service');

        $this->assertInstanceOf(\stdClass::class, $resolved);
    }

    public function testBindReturnsAppForChaining(): void
    {
        $app = App::init();
        $this->injectContainer($app);

        $result = $app->bind('test.service', new \stdClass());
        $this->assertSame($app, $result);
    }

    public function testBindWithSameAbstractAndConcrete(): void
    {
        $app = App::init();
        $this->injectContainer($app);

        $obj = new \stdClass();
        $app->bind(\stdClass::class, $obj);

        $this->assertSame($obj, $app->resolve(\stdClass::class));
    }

    public function testMakeCreatesInstance(): void
    {
        $app = App::init();
        $this->injectContainer($app);

        $obj = $app->make(\stdClass::class);
        $this->assertInstanceOf(\stdClass::class, $obj);
    }

    public function testMakeReturnsInstance(): void
    {
        $app = App::init();
        $this->injectContainer($app);

        $obj = $app->make(\stdClass::class);
        $this->assertInstanceOf(\stdClass::class, $obj);
    }

    // ─── 魔术方法 __get / __isset ────────────────────────────

    public function testIssetReturnsFalseWhenContainerNotInitialized(): void
    {
        $app = App::init();
        $ref = new \ReflectionProperty(App::class, 'diContainer');
        $this->assertFalse($ref->isInitialized($app));
    }

    public function testGetThrowsForUnknownProperty(): void
    {
        $app = App::init();
        $this->injectContainer($app);

        $this->expectException(\OutOfBoundsException::class);
        $app->nonExistentProperty;
    }

    public function testGetReturnsBoundService(): void
    {
        $app = App::init();
        $this->injectContainer($app);

        $obj = new \stdClass();
        $obj->value = 'test_value';
        $app->bind('test.service', $obj);

        $resolved = $app->{'test.service'};
        $this->assertSame($obj, $resolved);
    }

    public function testIssetReturnsTrueForBoundService(): void
    {
        $app = App::init();
        $this->injectContainer($app);

        $app->bind('test.service', new \stdClass());
        $this->assertTrue(isset($app->{'test.service'}));
    }

    public function testIssetReturnsFalseForUnboundService(): void
    {
        $app = App::init();
        $this->injectContainer($app);

        $this->assertFalse(isset($app->{'nonexistent.thing'}));
    }

    // ─── Boot 幂等性 ────────────────────────────────────────

    public function testBootReturnsSelfForChaining(): void
    {
        $app = App::init();
        $result = $app->boot();
        $this->assertSame($app, $result);
    }

    public function testBootIsIdempotent(): void
    {
        $app = App::init();
        $app->boot();

        $this->assertTrue($app->isBootstrapped());
        $this->assertTrue($app->isRegistered());

        // 第二次 boot 不应抛出异常或改变状态
        $app->boot();
        $this->assertTrue($app->isBootstrapped());
        $this->assertTrue($app->isRegistered());
    }

    // ─── Helpers ─────────────────────────────────────────────

    /**
     * 通过反射注入一个空的 Container，使 bind/resolve/__get 等方法可用
     */
    private function injectContainer(App $app): void
    {
        $ref = new \ReflectionProperty(App::class, 'diContainer');
        $ref->setAccessible(true);
        $ref->setValue($app, new Container());
    }
}
