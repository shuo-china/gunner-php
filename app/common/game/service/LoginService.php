<?php
declare(strict_types=1);

namespace app\common\game\service;

use app\common\game\exception\GameException;
use app\common\game\platform\IdentityProvider;
use think\facade\Db;

final class LoginService
{
    public function login(IdentityProvider $provider, string $code, string $ip): array
    {
        if ($code === '' || strlen($code) > 256 || preg_match('/\s/', $code)) {
            throw new GameException('PARAM_ERROR', '请提供有效的登录 code', 422);
        }
        // Exchange once, outside the DB transaction. A code must never be reused on a DB retry.
        $identity = $provider->exchange($code);
        for ($attempt = 0; ; $attempt++) {
            try {
                return Db::transaction(function () use ($identity, $ip) {
                    $now = time();
                    $row = Db::name('game_identity')->where([
                        'provider' => $identity['provider'], 'app_id' => $identity['app_id'], 'openid' => $identity['openid'],
                    ])->lock(true)->find();
                    $created = !$row;
                    if ($created) {
                        $userId = (int) Db::name('user')->insertGetId([
                            'nickname' => '玩家', 'mobile' => null, 'avatar' => '', 'status' => 1,
                            'last_login_ip' => $ip, 'last_login_time' => $now,
                            'create_time' => $now, 'update_time' => $now, 'delete_time' => null,
                        ]);
                        $identityId = (int) Db::name('game_identity')->insertGetId([
                            'user_id' => $userId, 'provider' => $identity['provider'], 'app_id' => $identity['app_id'],
                            'openid' => $identity['openid'], 'unionid' => $identity['unionid'],
                            'create_time' => $now, 'update_time' => $now, 'last_login_time' => $now,
                        ]);
                        $row = ['id' => $identityId, 'user_id' => $userId] + $identity;
                    }
                    $user = Db::name('user')->where('id', $row['user_id'])->lock(true)->find();
                    if (!$user || (int) $user['status'] !== 1 || $user['delete_time'] !== null) {
                        throw new GameException('ACCOUNT_DISABLED', '账号不可用，请联系管理员', 403);
                    }
                    Db::name('user')->where('id', $user['id'])->update([
                        'last_login_time' => $now, 'last_login_ip' => $ip, 'update_time' => $now,
                    ]);
                    $changes = ['last_login_time' => $now, 'update_time' => $now];
                    if ($identity['unionid'] !== null) $changes['unionid'] = $identity['unionid'];
                    Db::name('game_identity')->where('id', $row['id'])->update($changes);
                    $token = (new GameSessionService())->issue((int) $user['id'], (int) $row['id']);
                    return ['player' => GameSessionService::player($user, $row), 'is_new_player' => $created,
                        'token_info' => $token, 'server_time_ms' => GameSessionService::nowMs()];
                });
            } catch (\Throwable $error) {
                // Concurrent first logins can deadlock on a gap lock or hit the identity unique key.
                // Roll back the user/session too, then reread the winning identity in a new transaction.
                $details = $error instanceof \think\db\exception\PDOException ? $error->getData() : [];
                $driver = $details['PDO Error Info']['Driver Error Code'] ?? null;
                if ($attempt >= 2 || !in_array((int) $driver, [1062, 1213, 1205], true)) throw $error;
            }
        }
    }
}
