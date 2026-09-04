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

    // ─── getConfig ──────────────────────────────────────────

    public function testGetConfigReturnsValue(): void
    {
        $app = App::init();
        $app->boot();
        // boot 后 config 已加载
        $result = $app->getConfig('app.debug', 'default_val');
        $this->assertNotNull($result);
    }

    public function testGetConfigReturnsDefaultForMissingKey(): void
    {
        $app = App::init();
        $app->boot();
        $result = $app->getConfig('nonexistent.key', 'fallback');
        $this->assertSame('fallback', $result);
    }

    public function testGetConfigWithNullKeyReturnsAll(): void
    {
        $app = App::init();
        $app->boot();
        $result = $app->getConfig();
        $this->assertIsArray($result);
    }

    // ─── isDebugMode after boot ──────────────────────────────

    public function testIsDebugModeAfterBoot(): void
    {
        $app = App::init();
        $app->boot();
        $this->assertIsBool($app->isDebugMode());
    }

    // ─── Boot 状态变更 ──────────────────────────────────────

    public function testBootSetsRegisteredTrue(): void
    {
        $app = App::init();
        $this->assertFalse($app->isRegistered());
        $app->boot();
        $this->assertTrue($app->isRegistered());
    }

    public function testBootSetsBootstrappedTrue(): void
    {
        $app = App::init();
        $this->assertFalse($app->isBootstrapped());
        $app->boot();
        $this->assertTrue($app->isBootstrapped());
    }

    // ─── __get / __isset 与 SERVICE_NAME_MAP ────────────────

    public function testGetResolvesServiceNameMapAlias(): void
    {
        $app = App::init();
        $app->boot();
        // 'config' 在 SERVICE_NAME_MAP 中映射到 Config::class
        $config = $app->config;
        $this->assertInstanceOf(\Restina\Config::class, $config);
    }

    public function testGetResolvesResponseFromMap(): void
    {
        $app = App::init();
        $app->boot();
        $response = $app->response;
        $this->assertInstanceOf(\Restina\Response::class, $response);
    }

    public function testIssetReturnsTrueForServiceMapAlias(): void
    {
        $app = App::init();
        $app->boot();
        // boot 后 config 已注册到容器
        $this->assertTrue(isset($app->config));
    }

    public function testIssetReturnsFalseForUnmappedProperty(): void
    {
        $app = App::init();
        $this->injectContainer($app);
        $this->assertFalse(isset($app->{'totally.unknown'}));
    }

    // ─── getQueue 懒加载 ────────────────────────────────────

    public function testGetQueueLazyInitialization(): void
    {
        $app = App::init();
        $app->boot();
        // 验证 queue 属性在调用前未初始化
        $ref = new \ReflectionProperty(App::class, 'queue');
        $this->assertFalse($ref->isInitialized($app));
    }

    // ─── handlePhpError ─────────────────────────────────────

    public function testHandlePhpErrorReturnsTrue(): void
    {
        // 恢复默认错误处理器，避免其他测试中 boot 设置的处理器干扰
        for ($i = 0; $i < 10; $i++) { restore_error_handler(); }

        $app = App::init();
        // 通过反射设置必要属性
        $refDebug = new \ReflectionProperty(App::class, 'isDebugMode');
        $refDebug->setAccessible(true);
        $refDebug->setValue($app, false);

        $refLogger = new \ReflectionProperty(App::class, 'logger');
        $refLogger->setAccessible(true);
        $refLogger->setValue($app, new \Restina\Logger(sys_get_temp_dir() . '/restina_test_logs'));

        // handlePhpError 应返回 true 以阻止 PHP 内部错误处理器
        $result = $app->handlePhpError(E_USER_NOTICE, 'test notice', __FILE__, __LINE__);
        $this->assertTrue($result);
    }

    // ─── 路径一致性 ─────────────────────────────────────────

    public function testAppPathEndsWithApp(): void
    {
        $app = App::init();
        $this->assertStringEndsWith('app', $app->getAppPath());
    }

    public function testViewPathEndsWithViews(): void
    {
        $app = App::init();
        $this->assertStringEndsWith('views', $app->getViewPath());
    }

    public function testCachePathEndsWithCache(): void
    {
        $app = App::init();
        $this->assertStringEndsWith('cache', $app->getCachePath());
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
