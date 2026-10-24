<?php
declare(strict_types=1);

namespace app\common\game\service;

use app\common\game\exception\GameException;
use think\facade\Db;

final class GameSessionService
{
    public static function nowMs(): int { return (int) floor(microtime(true) * 1000); }

    /** Called inside the login transaction. Only the digest is persisted. */
    public function issue(int $userId, int $identityId): array
    {
        $ttl = max(300, min(604800, (int) config('game.session_ttl', 86400)));
        $now = self::nowMs();
        $token = 'fg1_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        Db::name('game_session')->insert([
            'user_id' => $userId, 'identity_id' => $identityId,
            'token_hash' => hash('sha256', $token), 'expires_at_ms' => $now + $ttl * 1000,
            'revoked_at_ms' => null, 'create_time' => time(), 'last_seen_at_ms' => $now,
        ]);
        return ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => $ttl, 'expires_at_ms' => $now + $ttl * 1000];
    }

    public function authenticate(string $authorization): array
    {
        if (!preg_match('/^Bearer (fg1_[A-Za-z0-9_-]{43})$/D', $authorization, $match)) {
            throw new GameException('SESSION_INVALID', '请重新登录', 401);
        }
        $session = Db::name('game_session')->where('token_hash', hash('sha256', $match[1]))->find();
        if (!$session || $session['revoked_at_ms'] !== null || (int) $session['expires_at_ms'] <= self::nowMs()) {
            throw new GameException('SESSION_INVALID', '登录已过期，请重新登录', 401);
        }
        $user = Db::name('user')->where('id', $session['user_id'])->find();
        $identity = Db::name('game_identity')->where('id', $session['identity_id'])->where('user_id', $session['user_id'])->find();
        if (!$user || !$identity || (int) $user['status'] !== 1 || $user['delete_time'] !== null) {
            throw new GameException('ACCOUNT_DISABLED', '账号不可用，请联系管理员', 403);
        }
        return ['session_id' => $session['id'], 'user_id' => $user['id'], 'player' => self::player($user, $identity),
            'expires_at_ms' => (int) $session['expires_at_ms']];
    }

    public static function player(array $user, array $identity): array
    {
        return ['id' => (string) $user['id'], 'nickname' => (string) ($user['nickname'] ?? ''),
            'provider' => $identity['provider'], 'app_id' => $identity['app_id']];
    }

    public function revoke(array $session): void
    {
        Db::name('game_session')->where('id', $session['session_id'])->whereNull('revoked_at_ms')
            ->update(['revoked_at_ms' => self::nowMs()]);
    }
}
