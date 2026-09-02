<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Restina\Config;

class ConfigTest extends TestCase
{
    private Config $config;

    protected function setUp(): void
    {
        $this->config = new Config([
            'app' => [
                'name' => 'TestApp',
                'debug' => true,
            ],
            'database' => [
                'default' => 'mysql',
                'connections' => [
                    'mysql' => [
                        'host' => '127.0.0.1',
                        'port' => 3306,
                    ],
                ],
            ],
            'cache' => 'file',
        ]);
    }

    // ─── get ─────────────────────────────────────────────────

    public function testGetTopLevelKey(): void
    {
        $this->assertSame('file', $this->config->get('cache'));
    }

    public function testGetNestedKeyWithDotNotation(): void
    {
        $this->assertSame('TestApp', $this->config->get('app.name'));
    }

    public function testGetDeeplyNestedKey(): void
    {
        $this->assertSame('127.0.0.1', $this->config->get('database.connections.mysql.host'));
    }

    public function testGetReturnsDefaultForMissingKey(): void
    {
        $this->assertNull($this->config->get('nonexistent'));
        $this->assertSame('fallback', $this->config->get('nonexistent', 'fallback'));
    }

    public function testGetReturnsDefaultForMissingNestedKey(): void
    {
        $this->assertSame('default', $this->config->get('app.missing.key', 'default'));
    }

    public function testGetNullKeyReturnsAllConfig(): void
    {
        $all = $this->config->get(null);
        $this->assertIsArray($all);
        $this->assertArrayHasKey('app', $all);
        $this->assertArrayHasKey('database', $all);
    }

    public function testGetReturnsArrayForIntermediateKey(): void
    {
        $result = $this->config->get('database.connections');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('mysql', $result);
    }

    public function testGetOnEmptyConfig(): void
    {
        $config = new Config([]);
        $this->assertSame('default', $config->get('any.key', 'default'));
    }

    // ─── set ─────────────────────────────────────────────────

    public function testSetTopLevelKey(): void
    {
        $this->config->set('new_key', 'new_value');
        $this->assertSame('new_value', $this->config->get('new_key'));
    }

    public function testSetNestedKey(): void
    {
        $this->config->set('app.version', '1.0');
        $this->assertSame('1.0', $this->config->get('app.version'));
    }

    public function testSetCreatesIntermediateArrays(): void
    {
        $this->config->set('deep.nested.key', 'value');
        $this->assertSame('value', $this->config->get('deep.nested.key'));

        $intermediate = $this->config->get('deep.nested');
        $this->assertIsArray($intermediate);
    }

    public function testSetOverwritesExistingValue(): void
    {
        $this->config->set('app.name', 'NewApp');
        $this->assertSame('NewApp', $this->config->get('app.name'));
    }

    // ─── has ─────────────────────────────────────────────────

    public function testHasReturnsTrueForExisting(): void
    {
        $this->assertTrue($this->config->has('app.name'));
    }

    public function testHasReturnsFalseForMissing(): void
    {
        $this->assertFalse($this->config->has('nonexistent'));
    }

    public function testHasReturnsTrueForNestedExisting(): void
    {
        $this->assertTrue($this->config->has('database.connections.mysql.port'));
    }

    public function testHasReturnsFalseForPartialPath(): void
    {
        $this->assertFalse($this->config->has('app.name.sub'));
    }

    // ─── setMany ─────────────────────────────────────────────

    public function testSetManySetsMultipleKeys(): void
    {
        $this->config->setMany([
            'x' => 1,
            'y' => 2,
            'z.nested' => 3,
        ]);

        $this->assertSame(1, $this->config->get('x'));
        $this->assertSame(2, $this->config->get('y'));
        $this->assertSame(3, $this->config->get('z.nested'));
    }

    // ─── remove ──────────────────────────────────────────────

    public function testRemoveTopLevelKey(): void
    {
        $this->config->remove('cache');
        $this->assertFalse($this->config->has('cache'));
    }

    public function testRemoveNestedKey(): void
    {
        $this->config->remove('app.debug');
        $this->assertFalse($this->config->has('app.debug'));
        $this->assertTrue($this->config->has('app.name')); // 其他键不受影响
    }

    public function testRemoveNonexistentKeyIsNoop(): void
    {
        $this->config->remove('nonexistent.key');
        // 不应抛出异常
        $this->assertTrue(true);
    }

    // ─── all / clear ─────────────────────────────────────────

    public function testAllReturnsFullConfig(): void
    {
        $all = $this->config->all();
        $this->assertArrayHasKey('app', $all);
        $this->assertArrayHasKey('database', $all);
        $this->assertArrayHasKey('cache', $all);
    }

    public function testClearEmptiesAllConfig(): void
    {
        $this->config->clear();
        $this->assertSame([], $this->config->all());
        $this->assertNull($this->config->get('app'));
    }

    // ─── 构造 ────────────────────────────────────────────────

    public function testConstructWithEmptyArray(): void
    {
        $config = new Config();
        $this->assertSame([], $config->all());
    }

    public function testConstructWithDefaultValues(): void
    {
        $config = new Config(['key' => 'value']);
        $this->assertSame('value', $config->get('key'));
    }

    // ─── 布尔值和 null 值 ────────────────────────────────────

    public function testGetBooleanValue(): void
    {
        $this->assertTrue($this->config->get('app.debug'));
    }

    public function testSetNullValue(): void
    {
        $this->config->set('nullable', null);
        $this->assertNull($this->config->get('nullable'));
        $this->assertTrue($this->config->has('nullable'));
    }

    public function testSetZeroValue(): void
    {
        $this->config->set('zero', 0);
        $this->assertSame(0, $this->config->get('zero'));
    }
}
