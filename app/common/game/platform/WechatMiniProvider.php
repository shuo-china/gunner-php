<?php
declare(strict_types=1);

namespace app\common\game\platform;

use app\common\game\exception\GameException;

final class WechatMiniProvider implements IdentityProvider
{
    // No client-supplied URL, AppID, secret, openid, or test login branch.
    private const ENDPOINT = 'https://api.weixin.qq.com/sns/jscode2session';

    public function __construct(private array $settings, private ?\Closure $transport = null) {}

    public function exchange(string $code): array
    {
        $appId = (string) ($this->settings['app_id'] ?? '');
        $secret = (string) ($this->settings['app_secret'] ?? '');
        if (!preg_match('/^wx[0-9a-f]{16}$/D', $appId) || $secret === '') {
            throw new GameException('WECHAT_NOT_CONFIGURED', '微信登录暂未配置，请联系管理员', 503);
        }
        $query = ['appid' => $appId, 'secret' => $secret, 'js_code' => $code, 'grant_type' => 'authorization_code'];
        // Injection is available to CLI tests only; HTTP callers cannot select it.
        $body = $this->transport ? ($this->transport)(self::ENDPOINT, $query) : $this->request($query);
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new GameException('WECHAT_UNAVAILABLE', '微信登录服务暂时不可用，请稍后重试', 502);
        }
        $error = $data['errcode'] ?? 0;
        if ($error !== 0 && $error !== '0') {
            if (in_array((int) $error, [40029, 40163], true)) {
                throw new GameException('WECHAT_CODE_INVALID', '微信登录凭证无效，请重新登录', 401);
            }
            if ((int) $error === 40226) {
                throw new GameException('WECHAT_ACCOUNT_RESTRICTED', '当前微信账号暂无法登录，请联系管理员', 403);
            }
            if (in_array((int) $error, [40013, 40001, 40125], true)) {
                throw new GameException('WECHAT_CONFIG_ERROR', '微信登录配置有误，请联系管理员', 503);
            }
            if ((int) $error === 45011) {
                throw new GameException('WECHAT_RATE_LIMITED', '微信登录过于频繁，请稍后重试', 429, ['Retry-After' => '60']);
            }
            throw new GameException('WECHAT_UNAVAILABLE', '微信登录服务暂时不可用，请稍后重试', 502);
        }
        $openid = $data['openid'] ?? null;
        $sessionKey = $data['session_key'] ?? null;
        $unionid = $data['unionid'] ?? null;
        if (!is_string($openid) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $openid)
            || !is_string($sessionKey) || $sessionKey === ''
            || ($unionid !== null && (!is_string($unionid) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $unionid)))) {
            throw new GameException('WECHAT_UNAVAILABLE', '微信登录服务返回异常，请稍后重试', 502);
        }
        return ['provider' => 'wechat_mini', 'app_id' => $appId, 'openid' => $openid, 'unionid' => $unionid];
    }

    private function request(array $query): string
    {
        $curl = curl_init(self::ENDPOINT . '?' . http_build_query($query));
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ];
        // Scope trust configuration to this WeChat request; shared PHP settings stay untouched.
        $caFile = root_path() . 'certs/cacert.pem';
        if (is_file($caFile) && is_readable($caFile)) {
            $options[CURLOPT_CAINFO] = $caFile;
        }
        curl_setopt_array($curl, $options);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        // Never include the URL, curl error, upstream body, code or secret in an exception.
        if (!is_string($body) || $status !== 200 || strlen($body) > 65536) {
            throw new GameException('WECHAT_UNAVAILABLE', '连接微信登录服务失败，请稍后重试', 502);
        }
        return $body;
    }
}
