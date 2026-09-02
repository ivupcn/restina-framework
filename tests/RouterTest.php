<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Restina\Router;

class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
    }

    // ─── 路由注册 ────────────────────────────────────────────

    public function testGetRegistersRoute(): void
    {
        $this->router->get('/users', fn() => 'list');
        $routes = $this->router->getRoutes();

        $this->assertCount(1, $routes);
        $this->assertSame(['GET'], $routes[0]['methods']);
        $this->assertSame('/users', $routes[0]['path']);
    }

    public function testPostRegistersRoute(): void
    {
        $this->router->post('/users', fn() => 'create');
        $routes = $this->router->getRoutes();

        $this->assertSame(['POST'], $routes[0]['methods']);
    }

    public function testPutRegistersRoute(): void
    {
        $this->router->put('/users/{id}', fn() => 'update');
        $this->assertSame(['PUT'], $this->router->getRoutes()[0]['methods']);
    }

    public function testPatchRegistersRoute(): void
    {
        $this->router->patch('/users/{id}', fn() => 'patch');
        $this->assertSame(['PATCH'], $this->router->getRoutes()[0]['methods']);
    }

    public function testDeleteRegistersRoute(): void
    {
        $this->router->delete('/users/{id}', fn() => 'delete');
        $this->assertSame(['DELETE'], $this->router->getRoutes()[0]['methods']);
    }

    public function testHeadRegistersRoute(): void
    {
        $this->router->head('/ping', fn() => 'head');
        $this->assertSame(['HEAD'], $this->router->getRoutes()[0]['methods']);
    }

    public function testOptionsRegistersRoute(): void
    {
        $this->router->options('/cors', fn() => 'options');
        $this->assertSame(['OPTIONS'], $this->router->getRoutes()[0]['methods']);
    }

    public function testMapWithMultipleMethods(): void
    {
        $this->router->map(['GET', 'POST'], '/form', fn() => 'form');
        $routes = $this->router->getRoutes();

        $this->assertSame(['GET', 'POST'], $routes[0]['methods']);
    }

    public function testMapNormalizesMethodToUppercase(): void
    {
        $this->router->map('get', '/test', fn() => 'test');
        $this->assertSame(['GET'], $this->router->getRoutes()[0]['methods']);
    }

    public function testWildcardMethodRegistersAllMethods(): void
    {
        $this->router->map('*', '/any', fn() => 'any');
        $methods = $this->router->getRoutes()[0]['methods'];

        $this->assertContains('GET', $methods);
        $this->assertContains('POST', $methods);
        $this->assertContains('PUT', $methods);
        $this->assertContains('DELETE', $methods);
        $this->assertContains('OPTIONS', $methods);
    }

    public function testRouteWithMiddlewares(): void
    {
        $mw = fn($req, $next) => $next($req);
        $this->router->get('/protected', fn() => 'secret', [$mw]);

        $route = $this->router->getRoutes()[0];
        $this->assertCount(1, $route['middlewares']);
    }

    // ─── 路由分组 ────────────────────────────────────────────

    public function testGroupAddsPrefix(): void
    {
        $this->router->group('/api', function (Router $r) {
            $r->get('/users', fn() => 'users');
            $r->get('/posts', fn() => 'posts');
        });

        $routes = $this->router->getRoutes();
        $this->assertCount(2, $routes);
        $this->assertSame('/api/users', $routes[0]['path']);
        $this->assertSame('/api/posts', $routes[1]['path']);
    }

    public function testNestedGroups(): void
    {
        $this->router->group('/api', function (Router $r) {
            $r->group('/v1', function (Router $r) {
                $r->get('/users', fn() => 'users');
            });
        });

        $routes = $this->router->getRoutes();
        $this->assertSame('/api/v1/users', $routes[0]['path']);
    }

    // ─── registerRoutes 批量注册 ─────────────────────────────

    public function testRegisterRoutesWithListFormat(): void
    {
        $handler = fn() => 'ok';
        $trie = $this->router->registerRoutes([
            ['GET', '/a', $handler],
            ['POST', '/b', $handler, [fn($req, $next) => $next($req)]],
        ]);

        $this->assertIsArray($trie);
        $this->assertCount(2, $this->router->getRoutes());
    }

    public function testRegisterRoutesWithAssocFormat(): void
    {
        $handler = fn() => 'ok';
        $trie = $this->router->registerRoutes([
            ['method' => 'GET', 'path' => '/x', 'handler' => $handler],
            ['method' => 'POST', 'path' => '/y', 'handler' => $handler, 'middlewares' => []],
        ]);

        $this->assertIsArray($trie);
        $this->assertCount(2, $this->router->getRoutes());
    }

    public function testRegisterRoutesReturnsNullForEmptyArray(): void
    {
        $result = $this->router->registerRoutes([]);
        $this->assertNull($result);
    }

    // ─── matchPath 匹配与参数提取 ────────────────────────────

    public function testMatchPathStaticRoute(): void
    {
        $result = $this->router->matchPath('/users', '/users');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testMatchPathExtractsSingleParam(): void
    {
        $result = $this->router->matchPath('/users/{id}', '/users/42');
        $this->assertIsArray($result);
        $this->assertSame('42', $result['id']);
    }

    public function testMatchPathExtractsMultipleParams(): void
    {
        $result = $this->router->matchPath('/users/{userId}/posts/{postId}', '/users/5/posts/99');
        $this->assertIsArray($result);
        $this->assertSame('5', $result['userId']);
        $this->assertSame('99', $result['postId']);
    }

    public function testMatchPathWithCustomRegex(): void
    {
        $result = $this->router->matchPath('/users/{id:[0-9]+}', '/users/42');
        $this->assertIsArray($result);
        $this->assertSame('42', $result['id']);
    }

    public function testMatchPathFailsWithCustomRegex(): void
    {
        $result = $this->router->matchPath('/users/{id:[0-9]+}', '/users/abc');
        $this->assertFalse($result);
    }

    public function testMatchPathReturnsFalseForNoMatch(): void
    {
        $result = $this->router->matchPath('/users', '/posts');
        $this->assertFalse($result);
    }

    public function testMatchPathRootRoute(): void
    {
        $result = $this->router->matchPath('/', '/');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testMatchPathDoesNotPartialMatch(): void
    {
        // /users 不应匹配 /users/extra
        $result = $this->router->matchPath('/users', '/users/extra');
        $this->assertFalse($result);
    }

    // ─── Trie 构建与 dispatch ────────────────────────────────

    public function testBuildAndReturnTrie(): void
    {
        $this->router->get('/hello', fn() => 'world');
        $trie = $this->router->buildAndReturnTrie();

        $this->assertIsArray($trie);
        $this->assertArrayHasKey('children', $trie);
        $this->assertArrayHasKey('routeData', $trie);
    }

    public function testBuildTrieWithDynamicSegment(): void
    {
        $this->router->get('/users/{id}', fn() => 'show');
        $trie = $this->router->buildAndReturnTrie();

        // 根节点应有 'users' 子节点
        $this->assertArrayHasKey('users', $trie['children']);
        // 'users' 节点应有 __PARAM__ 子节点
        $usersNode = $trie['children']['users'];
        $this->assertArrayHasKey('__PARAM__', $usersNode['children']);
        $this->assertSame('id', $usersNode['children']['__PARAM__']['paramName']);
    }

    // ─── getDebugInfo ────────────────────────────────────────

    public function testGetDebugInfo(): void
    {
        $this->router->get('/a', fn() => 'a');
        $this->router->post('/b', fn() => 'b');
        $this->router->get('/users/{id}', fn() => 'show');

        $info = $this->router->getDebugInfo();

        $this->assertSame(3, $info['total_routes']);
        $this->assertContains('GET', $info['methods_used']);
        $this->assertContains('POST', $info['methods_used']);
        $this->assertContains('/a', $info['paths']);
        $this->assertTrue($info['has_dynamic_paths']);
    }

    public function testGetDebugInfoNoDynamicPaths(): void
    {
        $this->router->get('/static', fn() => 'ok');
        $info = $this->router->getDebugInfo();

        $this->assertFalse($info['has_dynamic_paths']);
    }

    // ─── 多路由注册同一方法 ──────────────────────────────────

    public function testMultipleRoutesSameMethod(): void
    {
        $this->router->get('/a', fn() => 'a');
        $this->router->get('/b', fn() => 'b');

        $this->assertCount(2, $this->router->getRoutes());
    }

    // ─── getRoutes 返回副本验证 ──────────────────────────────

    public function testGetRoutesReturnsAllRegistered(): void
    {
        $this->assertEmpty($this->router->getRoutes());

        $this->router->get('/one', fn() => 1);
        $this->router->post('/two', fn() => 2);
        $this->router->delete('/three', fn() => 3);

        $this->assertCount(3, $this->router->getRoutes());
    }
}
