<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Restina\Cache;
use Restina\Config;

// 确保 RUN_MODE 常量在测试环境中可用
if (!defined('RUN_MODE')) {
    define('RUN_MODE', 'cli');
}

class CacheTest extends TestCase
{
    private Cache $cache;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/restina_cache_test_' . uniqid();
        mkdir($this->cacheDir, 0755, true);

        $config = new Config([
            'app' => [
                'cache' => 'file',
                'cache_dir' => $this->cacheDir,
            ],
            'redis' => [
                'prefix' => 'test:',
            ],
        ]);

        $this->cache = new Cache($config, $this->cacheDir);
    }

    protected function tearDown(): void
    {
        // 递归清理测试缓存目录
        if (is_dir($this->cacheDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->cacheDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    unlink($file->getPathname());
                }
            }
            rmdir($this->cacheDir);
        }
    }

    // ─── set / get ───────────────────────────────────────────

    public function testSetAndGetString(): void
    {
        $this->cache->set('name', 'Restina');
        $this->assertSame('Restina', $this->cache->get('name'));
    }

    public function testSetAndGetInteger(): void
    {
        $this->cache->set('count', 42);
        $this->assertSame(42, $this->cache->get('count'));
    }

    public function testSetAndGetArray(): void
    {
        $data = ['key' => 'value', 'nested' => ['a' => 1]];
        $this->cache->set('data', $data);
        $this->assertSame($data, $this->cache->get('data'));
    }

    public function testSetAndGetBoolean(): void
    {
        $this->cache->set('flag', true);
        $this->assertTrue($this->cache->get('flag'));

        $this->cache->set('off', false);
        $this->assertFalse($this->cache->get('off'));
    }

    public function testGetReturnsDefaultForMissingKey(): void
    {
        $this->assertNull($this->cache->get('nonexistent'));
        $this->assertSame('fallback', $this->cache->get('nonexistent', 'fallback'));
    }

    public function testSetOverwritesExistingValue(): void
    {
        $this->cache->set('key', 'old');
        $this->cache->set('key', 'new');
        $this->assertSame('new', $this->cache->get('key'));
    }

    // ─── TTL / 过期 ──────────────────────────────────────────

    public function testExpiredItemReturnsDefault(): void
    {
        // TTL 为 1 秒，设置后等待过期
        $this->cache->set('ephemeral', 'gone', 1);
        $this->assertSame('gone', $this->cache->get('ephemeral'));

        sleep(2);
        $this->assertNull($this->cache->get('ephemeral'));
    }

    // ─── has ─────────────────────────────────────────────────

    public function testHasReturnsTrueForExisting(): void
    {
        $this->cache->set('exists', 'yes');
        $this->assertTrue($this->cache->has('exists'));
    }

    public function testHasReturnsFalseForMissing(): void
    {
        $this->assertFalse($this->cache->has('missing'));
    }

    public function testHasReturnsFalseForExpired(): void
    {
        $this->cache->set('temp', 'val', 1);
        sleep(2);
        $this->assertFalse($this->cache->has('temp'));
    }

    // ─── delete ──────────────────────────────────────────────

    public function testDeleteRemovesItem(): void
    {
        $this->cache->set('to_delete', 'value');
        $this->assertTrue($this->cache->delete('to_delete'));
        $this->assertNull($this->cache->get('to_delete'));
    }

    public function testDeleteNonexistentReturnsTrue(): void
    {
        $this->assertTrue($this->cache->delete('nonexistent'));
    }

    // ─── clear ───────────────────────────────────────────────

    public function testClearRemovesAllItems(): void
    {
        $this->cache->set('a', 1);
        $this->cache->set('b', 2);
        $this->cache->set('c', 3);

        $this->cache->clear();

        $this->assertNull($this->cache->get('a'));
        $this->assertNull($this->cache->get('b'));
        $this->assertNull($this->cache->get('c'));
    }

    // ─── getMultiple / setMultiple / deleteMultiple ──────────

    public function testSetMultipleAndGetMultiple(): void
    {
        $this->cache->setMultiple(['x' => 1, 'y' => 2, 'z' => 3]);

        $results = $this->cache->getMultiple(['x', 'y', 'z']);
        $this->assertSame(1, $results['x']);
        $this->assertSame(2, $results['y']);
        $this->assertSame(3, $results['z']);
    }

    public function testGetMultipleWithMissingKeys(): void
    {
        $this->cache->set('exists', 'yes');

        $results = $this->cache->getMultiple(['exists', 'missing'], 'default');
        $this->assertSame('yes', $results['exists']);
        $this->assertSame('default', $results['missing']);
    }

    public function testDeleteMultiple(): void
    {
        $this->cache->setMultiple(['a' => 1, 'b' => 2, 'c' => 3]);

        $this->cache->deleteMultiple(['a', 'b']);

        $this->assertNull($this->cache->get('a'));
        $this->assertNull($this->cache->get('b'));
        $this->assertSame(3, $this->cache->get('c'));
    }

    // ─── gc ──────────────────────────────────────────────────

    public function testGcRemovesExpiredFiles(): void
    {
        $this->cache->set('alive', 'yes', 3600);
        $this->cache->set('dead', 'yes', 1);

        sleep(2);
        $this->cache->gc();

        $this->assertSame('yes', $this->cache->get('alive'));
        // dead 已被 GC 清理
        $files = glob($this->cacheDir . '/*.cache');
        $aliveCount = 0;
        foreach ($files as $f) {
            $data = unserialize(file_get_contents($f), ['allowed_classes' => false]);
            if (is_array($data) && $data['value'] === 'yes' && isset($data['expires_at']) && $data['expires_at'] > time()) {
                $aliveCount++;
            }
        }
        $this->assertSame(1, $aliveCount);
    }

    // ─── isUsingRedis ────────────────────────────────────────

    public function testIsNotUsingRedisInFileMode(): void
    {
        $this->assertFalse($this->cache->isUsingRedis());
    }

    public function testGetRedisReturnsNullInFileMode(): void
    {
        $this->assertNull($this->cache->getRedis());
    }
}
