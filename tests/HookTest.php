<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Restina\Hook;

class HookTest extends TestCase
{
    protected function setUp(): void
    {
        Hook::reset();
    }

    protected function tearDown(): void
    {
        Hook::reset();
    }

    // ─── Action ──────────────────────────────────────────────

    public function testAddAndDoAction(): void
    {
        $called = false;
        Hook::addAction('test.event', function () use (&$called) {
            $called = true;
        });

        Hook::doAction('test.event');
        $this->assertTrue($called);
    }

    public function testActionReceivesArguments(): void
    {
        $receivedArgs = [];
        Hook::addAction('test.args', function (...$args) use (&$receivedArgs) {
            $receivedArgs = $args;
        });

        Hook::doAction('test.args', 'a', 'b', 'c');
        $this->assertSame(['a', 'b', 'c'], $receivedArgs);
    }

    public function testActionPriorityOrdering(): void
    {
        $order = [];

        Hook::addAction('test.order', function () use (&$order) {
            $order[] = 'second';
        }, 20);

        Hook::addAction('test.order', function () use (&$order) {
            $order[] = 'first';
        }, 5);

        Hook::addAction('test.order', function () use (&$order) {
            $order[] = 'third';
        }, 30);

        Hook::doAction('test.order');
        $this->assertSame(['first', 'second', 'third'], $order);
    }

    public function testActionDeduplication(): void
    {
        $count = 0;
        $callback = function () use (&$count) {
            $count++;
        };

        Hook::addAction('test.dedup', $callback);
        Hook::addAction('test.dedup', $callback); // 重复注册

        Hook::doAction('test.dedup');
        $this->assertSame(1, $count);
    }

    public function testDoActionOnNonexistentHook(): void
    {
        // 不应抛出异常
        Hook::doAction('nonexistent.hook');
        $this->assertTrue(true);
    }

    public function testHasAction(): void
    {
        $this->assertFalse(Hook::hasAction('test.exists'));

        Hook::addAction('test.exists', function () {});
        $this->assertTrue(Hook::hasAction('test.exists'));
    }

    public function testRemoveAllActions(): void
    {
        Hook::addAction('test.remove', function () {});
        $this->assertTrue(Hook::hasAction('test.remove'));

        Hook::removeAction('test.remove');
        $this->assertFalse(Hook::hasAction('test.remove'));
    }

    public function testRemoveSpecificAction(): void
    {
        $callLog = [];

        $keep = function () use (&$callLog) {
            $callLog[] = 'keep';
        };
        $remove = function () use (&$callLog) {
            $callLog[] = 'remove';
        };

        Hook::addAction('test.specific', $keep);
        Hook::addAction('test.specific', $remove);

        Hook::removeAction('test.specific', $remove);
        Hook::doAction('test.specific');

        $this->assertSame(['keep'], $callLog);
    }

    // ─── Filter ──────────────────────────────────────────────

    public function testAddAndApplyFilter(): void
    {
        Hook::addFilter('test.upper', function (string $value) {
            return strtoupper($value);
        });

        $result = Hook::applyFilters('test.upper', 'hello');
        $this->assertSame('HELLO', $result);
    }

    public function testFilterChaining(): void
    {
        Hook::addFilter('test.chain', function (string $value) {
            return $value . ' world';
        }, 10);

        Hook::addFilter('test.chain', function (string $value) {
            return $value . '!';
        }, 20);

        $result = Hook::applyFilters('test.chain', 'hello');
        $this->assertSame('hello world!', $result);
    }

    public function testFilterReceivesExtraArgs(): void
    {
        Hook::addFilter('test.extra', function (string $value, string $suffix) {
            return $value . $suffix;
        });

        $result = Hook::applyFilters('test.extra', 'hello', ' world');
        $this->assertSame('hello world', $result);
    }

    public function testFilterReturnsOriginalWhenNoHooks(): void
    {
        $result = Hook::applyFilters('nonexistent', 'original');
        $this->assertSame('original', $result);
    }

    public function testHasFilter(): void
    {
        $this->assertFalse(Hook::hasFilter('test.f'));

        Hook::addFilter('test.f', function ($v) { return $v; });
        $this->assertTrue(Hook::hasFilter('test.f'));
    }

    public function testRemoveFilter(): void
    {
        Hook::addFilter('test.rf', function ($v) { return strtoupper($v); });
        Hook::removeFilter('test.rf');

        $result = Hook::applyFilters('test.rf', 'hello');
        $this->assertSame('hello', $result);
    }

    // ─── Pipe ────────────────────────────────────────────────

    public function testPipeWithNoRegisteredHandlers(): void
    {
        // 闭包 payload 应被直接执行
        $result = Hook::runPipe('empty.pipe', function () {
            return 'direct';
        });
        $this->assertSame('direct', $result);
    }

    public function testPipeWithNonCallablePayload(): void
    {
        // 非闭包 payload 无管道时直接返回
        $result = Hook::runPipe('empty.pipe', 'hello');
        $this->assertSame('hello', $result);
    }

    public function testPipeOnionModel(): void
    {
        $log = [];

        Hook::addPipe('test.onion', function ($request, $next) use (&$log) {
            $log[] = 'A-before';
            $result = $next($request);
            $log[] = 'A-after';
            return $result;
        }, 10);

        Hook::addPipe('test.onion', function ($request, $next) use (&$log) {
            $log[] = 'B-before';
            $result = $next($request);
            $log[] = 'B-after';
            return $result;
        }, 20);

        $result = Hook::runPipe('test.onion', function () use (&$log) {
            $log[] = 'handler';
            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame(['A-before', 'B-before', 'handler', 'B-after', 'A-after'], $log);
    }

    public function testPipeCanModifyResult(): void
    {
        Hook::addPipe('test.modify', function ($request, $next) {
            $result = $next($request);
            return $result . ' + modified';
        });

        $result = Hook::runPipe('test.modify', function () {
            return 'original';
        });

        $this->assertSame('original + modified', $result);
    }

    public function testHasPipe(): void
    {
        $this->assertFalse(Hook::hasPipe('test.p'));

        Hook::addPipe('test.p', function ($r, $n) { return $n($r); });
        $this->assertTrue(Hook::hasPipe('test.p'));
    }

    public function testRemovePipe(): void
    {
        Hook::addPipe('test.rp', function ($r, $n) { return $n($r); });
        Hook::removePipe('test.rp');
        $this->assertFalse(Hook::hasPipe('test.rp'));
    }

    // ─── Config ──────────────────────────────────────────────

    public function testSetAndGetConfig(): void
    {
        Hook::setConfig(['key' => 'value']);
        $this->assertSame(['key' => 'value'], Hook::getConfig());
    }

    public function testGetActionsFromConfig(): void
    {
        Hook::setConfig(['actions' => ['hook1' => 'handler']]);
        $this->assertSame(['hook1' => 'handler'], Hook::getActions());
    }

    public function testGetActionsReturnsEmptyArrayWhenNotSet(): void
    {
        Hook::setConfig([]);
        $this->assertSame([], Hook::getActions());
    }

    public function testGetFiltersFromConfig(): void
    {
        Hook::setConfig(['filters' => ['f1' => 'handler']]);
        $this->assertSame(['f1' => 'handler'], Hook::getFilters());
    }

    public function testGetPipesFromConfig(): void
    {
        Hook::setConfig(['pipes' => ['p1' => 'handler']]);
        $this->assertSame(['p1' => 'handler'], Hook::getPipes());
    }

    // ─── loadFromConfig ──────────────────────────────────────

    public function testLoadFromConfigRegistersActions(): void
    {
        $config = [
            'actions' => [
                'app.boot' => [function () {}],
            ],
        ];

        Hook::loadFromConfig($config);
        $this->assertTrue(Hook::hasAction('app.boot'));
    }

    public function testLoadFromConfigOnlyRunsOnce(): void
    {
        $count = 0;
        $config = [
            'actions' => [
                'test.once' => [function () use (&$count) { $count++; }],
            ],
        ];

        Hook::loadFromConfig($config);
        Hook::loadFromConfig($config); // 第二次调用应被忽略

        Hook::doAction('test.once');
        $this->assertSame(1, $count);
    }

    // ─── Reset ───────────────────────────────────────────────

    public function testResetClearsEverything(): void
    {
        Hook::addAction('a', function () {});
        Hook::addFilter('f', function ($v) { return $v; });
        Hook::addPipe('p', function ($r, $n) { return $n($r); });
        Hook::setConfig(['key' => 'val']);

        Hook::reset();

        $this->assertFalse(Hook::hasAction('a'));
        $this->assertFalse(Hook::hasFilter('f'));
        $this->assertFalse(Hook::hasPipe('p'));
        $this->assertSame([], Hook::getConfig());
    }

    // ─── getHookInfo ─────────────────────────────────────────

    public function testGetHookInfoReturnsAll(): void
    {
        Hook::addAction('a', function () {});
        Hook::addFilter('f', function ($v) { return $v; });

        $info = Hook::getHookInfo();
        $this->assertArrayHasKey('actions', $info);
        $this->assertArrayHasKey('filters', $info);
        $this->assertArrayHasKey('pipes', $info);
    }

    public function testGetHookInfoByType(): void
    {
        Hook::addAction('a', function () {});
        $info = Hook::getHookInfo('action');
        $this->assertArrayHasKey('a', $info);
    }
}
