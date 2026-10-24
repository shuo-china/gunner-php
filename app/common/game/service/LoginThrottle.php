<?php
declare(strict_types=1);

namespace app\common\game\service;

use app\common\game\exception\GameException;
use think\facade\Db;

final class LoginThrottle
{
    public function consume(string $ip): void
    {
        $now = time();
        $window = intdiv($now, 60);
        $key = hash('sha256', 'wechat|' . $ip . '|' . $window);
        $prefix = (string) config('database.connections.mysql.prefix', '');
        if (!preg_match('/^[A-Za-z0-9_]*$/D', $prefix)) throw new \LogicException('Invalid database prefix');
        $table = '`' . $prefix . 'game_login_throttle`';
        // A unique key and atomic increment work across concurrent PHP workers.
        Db::execute("INSERT INTO $table (bucket_key, attempts, expires_at) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE attempts = attempts + 1", [$key, ($window + 1) * 60]);
        $attempts = (int) Db::name('game_login_throttle')->where('bucket_key', $key)->value('attempts');
        if ($attempts > max(1, (int) config('game.login_rate', 30))) {
            throw new GameException('TOO_MANY_REQUESTS', '登录过于频繁，请稍后重试', 429, ['Retry-After' => (string) (60 - $now % 60)]);
        }
    }
}
