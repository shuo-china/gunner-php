<?php
declare(strict_types=1);

namespace app\game\controller;

use app\common\game\exception\GameException;
use app\common\game\platform\IdentityProvider;
use app\common\game\platform\TapTapMiniProvider;
use app\common\game\platform\WechatMiniProvider;
use app\common\game\service\GameSessionService;
use app\common\game\service\LoginService;
use app\common\game\service\LoginThrottle;

final class AccessController extends \app\common\BaseController
{
    public function wechat()
    {
        return $this->login(new WechatMiniProvider((array) config('game.wechat')));
    }

    public function taptap()
    {
        return $this->login($this->app->make(TapTapMiniProvider::class, ['settings' => (array) config('game.taptap')]));
    }

    private function login(IdentityProvider $provider)
    {
        // Use the peer address. Forwarded headers are not trusted by this login limiter.
        $ip = (string) $this->request->server('REMOTE_ADDR', '127.0.0.1');
        (new LoginThrottle())->consume($ip);
        $body = $this->request->getInput();
        if (strlen($body) > 4096 || stripos((string) $this->request->header('Content-Type', ''), 'application/json') !== 0) {
            throw new GameException('PARAM_ERROR', '请使用 JSON 提交登录 code', 422);
        }
        $data = json_decode($body, true);
        if (!is_array($data) || array_keys($data) !== ['code'] || !is_string($data['code'])) {
            throw new GameException('PARAM_ERROR', '登录仅接受 code 字段', 422);
        }
        $result = (new LoginService())->login($provider, $data['code'], $ip);
        // Return only after the transaction commits.
        return json($result, 200, ['Cache-Control' => 'no-store']);
    }

    public function me()
    {
        $session = $this->request->gameSession;
        return json(['player' => $session['player'], 'expires_at_ms' => $session['expires_at_ms'],
            'server_time_ms' => GameSessionService::nowMs()], 200, ['Cache-Control' => 'no-store']);
    }

    public function logout()
    {
        (new GameSessionService())->revoke($this->request->gameSession);
        return json(['logged_out' => true], 200, ['Cache-Control' => 'no-store']);
    }
}
