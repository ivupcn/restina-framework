<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Restina\Jwt;
use Restina\Config;
use Firebase\JWT\SignatureInvalidException;

class JwtTest extends TestCase
{
    private Jwt $jwt;

    protected function setUp(): void
    {
        $config = new Config([
            'jwt' => [
                'secret' => 'test-secret-key-for-unit-tests-min-32-bytes-long!!',
                'algorithm' => 'HS256',
                'expire_time' => 3600,
                'refresh_expire_time' => 7200,
                'leeway' => 5,
            ],
        ]);

        $this->jwt = new Jwt($config);
    }

    // ─── generateToken ───────────────────────────────────────

    public function testGenerateTokenReturnsString(): void
    {
        $token = $this->jwt->generateToken(['user_id' => 1]);
        $this->assertIsString($token);
        $this->assertNotEmpty($token);
    }

    public function testGenerateTokenProducesJwtFormat(): void
    {
        $token = $this->jwt->generateToken(['user_id' => 1]);
        // JWT 由三部分组成，以 . 分隔
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);
    }

    public function testGenerateTokenWithCustomExpire(): void
    {
        $token = $this->jwt->generateToken(['id' => 1], 60);
        $data = $this->jwt->getTokenData($token);
        $this->assertSame(1, $data['id']);
    }

    // ─── verifyTokenStrict ───────────────────────────────────

    public function testVerifyTokenStrictReturnsDecoded(): void
    {
        $token = $this->jwt->generateToken(['user_id' => 42, 'role' => 'admin']);
        $decoded = $this->jwt->verifyTokenStrict($token);

        $this->assertIsObject($decoded);
        $this->assertSame(42, $decoded->data->user_id);
        $this->assertSame('admin', $decoded->data->role);
    }

    public function testVerifyTokenStrictThrowsForInvalidSignature(): void
    {
        $token = $this->jwt->generateToken(['id' => 1]);

        // 用不同 secret 创建另一个 Jwt 实例
        $otherConfig = new Config(['jwt' => ['secret' => 'different-secret-key-that-is-at-least-32-bytes']]);
        $otherJwt = new Jwt($otherConfig);

        $this->expectException(\Exception::class);
        $otherJwt->verifyTokenStrict($token);
    }

    // ─── verifyTokenBasic ────────────────────────────────────

    public function testVerifyTokenBasicWorksWithExpiredToken(): void
    {
        // 创建一个已经过期的 token（expire_time = -1 秒）
        $config = new Config([
            'jwt' => [
                'secret' => 'test-secret-key-for-unit-tests-min-32-bytes-long!!',
                'algorithm' => 'HS256',
                'expire_time' => -10,
                'leeway' => 5,
            ],
        ]);
        $expiredJwt = new Jwt($config);
        $token = $expiredJwt->generateToken(['id' => 99]);

        // basic 模式应该仍然能解码
        $decoded = $this->jwt->verifyTokenBasic($token);
        $this->assertSame(99, $decoded->data->id);
    }

    // ─── getTokenData ────────────────────────────────────────

    public function testGetTokenDataReturnsPayload(): void
    {
        $payload = ['user_id' => 7, 'name' => 'test'];
        $token = $this->jwt->generateToken($payload);

        $data = $this->jwt->getTokenData($token);
        $this->assertSame(7, $data['user_id']);
        $this->assertSame('test', $data['name']);
    }

    // ─── getTokenDataAllowExpired ────────────────────────────

    public function testGetTokenDataAllowExpiredWorksWithExpired(): void
    {
        $config = new Config([
            'jwt' => [
                'secret' => 'test-secret-key-for-unit-tests-min-32-bytes-long!!',
                'algorithm' => 'HS256',
                'expire_time' => -10,
                'leeway' => 5,
            ],
        ]);
        $expiredJwt = new Jwt($config);
        $token = $expiredJwt->generateToken(['id' => 55]);

        $data = $this->jwt->getTokenDataAllowExpired($token);
        $this->assertSame(55, $data['id']);
    }

    // ─── refreshToken ────────────────────────────────────────

    public function testRefreshTokenReturnsNewValidToken(): void
    {
        $token = $this->jwt->generateToken(['user_id' => 10]);
        $newToken = $this->jwt->refreshToken($token);

        $this->assertIsString($newToken);

        // 刷新后的 token 应该包含相同的业务数据
        $data = $this->jwt->getTokenData($newToken);
        $this->assertSame(10, $data['user_id']);

        // 刷新后的 token 应该是有效的
        $this->assertTrue($this->jwt->isValid($newToken));
    }

    // ─── isValid ─────────────────────────────────────────────

    public function testIsValidReturnsTrueForValidToken(): void
    {
        $token = $this->jwt->generateToken(['id' => 1]);
        $this->assertTrue($this->jwt->isValid($token));
    }

    public function testIsValidReturnsFalseForGarbage(): void
    {
        $this->assertFalse($this->jwt->isValid('not.a.token'));
    }

    public function testIsValidReturnsFalseForEmptyString(): void
    {
        $this->assertFalse($this->jwt->isValid(''));
    }

    // ─── isExpired ───────────────────────────────────────────

    public function testIsExpiredReturnsFalseForFreshToken(): void
    {
        $token = $this->jwt->generateToken(['id' => 1]);
        $this->assertFalse($this->jwt->isExpired($token));
    }

    public function testIsExpiredReturnsTrueForExpiredToken(): void
    {
        $config = new Config([
            'jwt' => [
                'secret' => 'test-secret-key-for-unit-tests-min-32-bytes-long!!',
                'algorithm' => 'HS256',
                'expire_time' => -10,
                'leeway' => 5,
            ],
        ]);
        $expiredJwt = new Jwt($config);
        $token = $expiredJwt->generateToken(['id' => 1]);

        $this->assertTrue($this->jwt->isExpired($token));
    }

    // ─── getRemainingTtl ─────────────────────────────────────

    public function testGetRemainingTtlReturnsPositiveForFreshToken(): void
    {
        $token = $this->jwt->generateToken(['id' => 1]);
        $ttl = $this->jwt->getRemainingTtl($token);

        // 应该接近 3600 秒（允许几秒误差）
        $this->assertGreaterThan(3500, $ttl);
        $this->assertLessThanOrEqual(3600, $ttl);
    }

    public function testGetRemainingTtlReturnsNegativeForExpired(): void
    {
        $config = new Config([
            'jwt' => [
                'secret' => 'test-secret-key-for-unit-tests-min-32-bytes-long!!',
                'algorithm' => 'HS256',
                'expire_time' => -100,
                'leeway' => 5,
            ],
        ]);
        $expiredJwt = new Jwt($config);
        $token = $expiredJwt->generateToken(['id' => 1]);

        $ttl = $this->jwt->getRemainingTtl($token);
        $this->assertLessThan(0, $ttl);
    }

    // ─── getIssuedAt ─────────────────────────────────────────

    public function testGetIssuedAtReturnsTimestamp(): void
    {
        $before = time();
        $token = $this->jwt->generateToken(['id' => 1]);
        $after = time();

        $iat = $this->jwt->getIssuedAt($token);
        $this->assertNotNull($iat);
        $this->assertGreaterThanOrEqual($before, $iat);
        $this->assertLessThanOrEqual($after, $iat);
    }

    public function testGetIssuedAtReturnsNullForInvalidToken(): void
    {
        $this->assertNull($this->jwt->getIssuedAt('invalid.token.here'));
    }

    // ─── 空 payload ──────────────────────────────────────────

    public function testGenerateTokenWithEmptyPayload(): void
    {
        $token = $this->jwt->generateToken();
        $data = $this->jwt->getTokenData($token);
        $this->assertIsArray($data);
        $this->assertEmpty($data);
    }
}
