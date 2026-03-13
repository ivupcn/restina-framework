<?php
// restina/Jwt.php

namespace Restina;

use Firebase\JWT\JWT as FirebaseJWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Restina\Config;

class Jwt
{
    private string $secret;
    private string $algorithm;
    private int $expireTime;
    private int $leeway; // 添加时间偏移量，用于处理时钟偏差

    public function __construct(Config $config)
    {
        $this->secret = $config->get('jwt.secret', 'your-default-secret-key');
        $this->algorithm = $config->get('jwt.algorithm', 'HS256');
        $this->expireTime = $config->get('jwt.expire_time', 3600); // 默认1小时
        $this->leeway = $config->get('jwt.leeway', 60); // 默认60秒时间偏移量
    }

    /**
     * 生成 JWT Token
     */
    public function generateToken(array $payload = [], ?int $customExpireTime = null): string
    {
        $issuedAt = time();
        $expireAt = $issuedAt + ($customExpireTime ?? $this->expireTime);

        $token = [
            'iat' => $issuedAt,          // 签发时间
            'exp' => $expireAt,          // 过期时间
            'data' => $payload           // 用户数据
        ];

        return FirebaseJWT::encode($token, $this->secret, $this->algorithm);
    }

    /**
     * 验证并解码 JWT Token（基础验证，不检查过期）
     */
    public function verifyTokenBasic(string $token): object
    {
        try {
            // 设置时间偏移量以处理时钟差异
            FirebaseJWT::$leeway = $this->leeway;
            $decoded = FirebaseJWT::decode($token, new Key($this->secret, $this->algorithm));
            return $decoded;
        } catch (SignatureInvalidException $e) {
            throw new SignatureInvalidException('Invalid token signature');
        } catch (\Exception $e) {
            throw new \Exception('Token verification failed: ' . $e->getMessage());
        }
    }

    /**
     * 验证并解码 JWT Token（严格模式，检查过期）
     */
    public function verifyTokenStrict(string $token): object
    {
        // 设置时间偏移量以处理时钟差异
        FirebaseJWT::$leeway = $this->leeway;

        try {
            $decoded = FirebaseJWT::decode($token, new Key($this->secret, $this->algorithm));
            // 额外检查过期时间（虽然库本身会检查，但保留这个检查作为双重保险）
            if (isset($decoded->exp) && $decoded->exp < time()) {
                throw new ExpiredException('Token has expired');
            }

            return $decoded;
        } catch (ExpiredException $e) {
            throw new ExpiredException('Token has expired');
        } catch (SignatureInvalidException $e) {
            throw new SignatureInvalidException('Invalid token signature');
        } catch (\Exception $e) {
            throw new \Exception('Token verification failed: ' . $e->getMessage());
        }
    }

    /**
     * 刷新 Token
     */
    public function refreshToken(string $token): string
    {
        // 解码过期token（不检查过期时间，只验证签名）
        $decoded = $this->verifyTokenBasic($token);

        // 重新生成带有新过期时间的 token
        $newPayload = (array)$decoded->data;
        return $this->generateToken($newPayload);
    }

    /**
     * 获取 Token 中的数据（基础模式，不检查过期）
     */
    public function getTokenDataAllowExpired(string $token): array
    {
        $decoded = $this->verifyTokenBasic($token);
        return (array)$decoded->data;
    }

    /**
     * 获取 Token 中的数据（严格模式，检查过期）
     */
    public function getTokenData(string $token): array
    {
        $decoded = $this->verifyTokenStrict($token);
        return (array)$decoded->data;
    }

    /**
     * 检查 Token 是否有效
     */
    public function isValid(string $token): bool
    {
        try {
            $this->verifyTokenStrict($token);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 检查 Token 是否已过期
     */
    public function isExpired(string $token): bool
    {
        try {
            $decoded = $this->verifyTokenBasic($token);
            if (isset($decoded->exp)) {
                return $decoded->exp < time();
            }
            return false;
        } catch (\Exception $e) {
            // 如果无法解码，认为是无效的，也可能已经过期
            return true;
        }
    }

    /**
     * 获取 Token 过期剩余时间（秒）
     * @return int 剩余秒数，负数表示已过期
     */
    public function getRemainingTtl(string $token): int
    {
        try {
            $decoded = $this->verifyTokenBasic($token);
            if (isset($decoded->exp)) {
                return $decoded->exp - time();
            }
            return 0;
        } catch (\Exception $e) {
            return -1; // 无法解析的token视为已过期
        }
    }

    /**
     * 获取 Token 发放时间
     */
    public function getIssuedAt(string $token): ?int
    {
        try {
            $decoded = $this->verifyTokenBasic($token);
            return $decoded->iat ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
