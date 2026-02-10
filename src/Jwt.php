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

    public function __construct(Config $config)
    {
        $this->secret = $config->get('jwt.secret', 'your-default-secret-key');
        $this->algorithm = $config->get('jwt.algorithm', 'HS256');
        $this->expireTime = $config->get('jwt.expire_time', 3600); // 默认1小时
    }

    /**
     * 生成 JWT Token
     */
    public function generateToken(array $payload = []): string
    {
        $issuedAt = time();
        $expireAt = $issuedAt + $this->expireTime;

        $token = [
            'iat' => $issuedAt,          // 签发时间
            'exp' => $expireAt,          // 过期时间
            'data' => $payload           // 用户数据
        ];

        return FirebaseJWT::encode($token, $this->secret, $this->algorithm);
    }

    /**
     * 验证并解码 JWT Token
     */
    public function verifyToken(string $token): object
    {
        try {
            $decoded = FirebaseJWT::decode($token, new Key($this->secret, $this->algorithm));

            // 验证过期时间
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
        $decoded = $this->verifyToken($token);

        // 重新生成带有新过期时间的 token
        $newPayload = (array)$decoded->data;
        return $this->generateToken($newPayload);
    }

    /**
     * 获取 Token 中的数据
     */
    public function getTokenData(string $token): array
    {
        $decoded = $this->verifyToken($token);
        return (array)$decoded->data;
    }

    /**
     * 检查 Token 是否有效
     */
    public function isValid(string $token): bool
    {
        try {
            $this->verifyToken($token);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
