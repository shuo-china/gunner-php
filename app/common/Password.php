<?php

namespace app\common;

class Password
{
    public static function make(string $password): string
    {
        return hash_hmac('sha256', md5($password), self::getSecret());
    }

    public static function verify(string $password, string $hash): bool
    {
        return hash_equals(self::make($password), $hash);
    }

    private static function getSecret(): string
    {
        return env('password.secret', env('jwt.secret', ''));
    }
}
