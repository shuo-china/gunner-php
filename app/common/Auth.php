<?php

namespace app\common;

use \Exception;
use think\facade\Request;

class Auth
{
    use Send;

    // jwt密钥
    protected static $secret = '';

    // token时效
    protected static $expires = 60 * 60 * 24;

    /**
     * 认证授权
     * @return array payload
     */
    final public static function authenticate()
    {
        try {
            return self::certification(self::getAccessToken());
        } catch (Exception $e) {
            self::error(401, $e->getMessage(), 'TOKEN_INVALID');
        }
    }

    /**
     * 验证权限
     * @param string $accessToken
     * @return array
     */
    private static function certification($accessToken)
    {
        try {
            return JWT::decode($accessToken, self::getSecret());
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * 获取请求的accessToken
     * @return string
     */
    private static function getAccessToken()
    {
        $authorization = Request::header('Authorization');

        if (preg_match('/^Bearer\s(\S+)/', $authorization, $matches)) {
            return $matches[1];
        } else {
            throw new Exception('令牌无效');
        }
    }

    /**
     * 设置AccessToken
     * @param mixed $sub 用户标识
     * @param array $info 用户信息
     */
    final public static function setAccessToken($sub, $info = [])
    {
        $role = app('http')->getName();

        $accessToken = self::buildAccessToken($sub, $info, $role);
        $accessTokenInfo = [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => self::$expires
        ];

        return $accessTokenInfo;
    }

    /**
     * 构建AccessToken
     * @param mixed $sub 用户标识
     * @param array $info 用户信息
     * @param string $role 角色
     * @return string
     */
    private static function buildAccessToken($sub, $info, $role)
    {
        $timestamp = time();
        $payload = [
            'iss' => Request::domain(),
            'iat' => $timestamp,
            'exp' => $timestamp + self::$expires,
            'nbf' => $timestamp,
            'sub' => $sub,
            'info' => $info,
            'role' => $role
        ];
        return JWT::encode($payload, self::getSecret());
    }

    private static function getSecret()
    {
        return env('jwt.secret', self::$secret);
    }

    /**
     * 获取用户信息(使用场景：没有使用admin_auth中间件，但想获得Token中的用户信息)
     * @return null | array
     */
    final public static function getClient()
    {
        try {
            $payload = self::certification(self::getAccessToken());
            if (app('http')->getName() === $payload['role']) {
                return [
                    'client_id' => $payload['sub'],
                    'client_info' => $payload['info']
                ];
            }
        } catch (Exception $e) {
        }

        return null;
    }
}
