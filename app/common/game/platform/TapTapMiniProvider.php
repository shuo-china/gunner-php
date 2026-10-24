<?php
declare(strict_types=1);

namespace app\common\game\platform;

use app\common\game\exception\GameException;

/** MiniApp credentials are server configuration, never HTTP request fields. */
final class TapTapMiniProvider implements IdentityProvider
{
    private const ENDPOINT = 'https://cloud-miniapp.tapapis.cn/auth/v1/jscode2session';

    public function __construct(private array $settings, private ?\Closure $transport = null) {}

    public function exchange(string $code): array
    {
        $appId = (string) ($this->settings['app_id'] ?? '');
        $secret = (string) ($this->settings['app_secret'] ?? '');
        if (!preg_match('/^tap[A-Za-z0-9_-]{1,125}$/D', $appId) || $secret === '' || $secret === 'fill-on-server') {
            throw new GameException('TAPTAP_NOT_CONFIGURED', 'TapTap 登录暂未配置，请联系管理员', 503);
        }
        $query = ['appid' => $appId, 'secret' => $secret, 'js_code' => $code, 'grant_type' => 'authorization_code'];
        // Only CLI tests inject transport; the public app always uses the fixed official endpoint.
        try {
            $body = $this->transport ? ($this->transport)(self::ENDPOINT, $query) : $this->request($query);
        } catch (\Throwable $error) {
            // Upstream exceptions may include a URL, secret or login code. Do not propagate them.
            throw $this->unavailable();
        }
        if (!is_string($body) || strlen($body) > 65536) throw $this->unavailable();
        $data = json_decode($body, true);
        if (!is_array($data)) throw $this->unavailable();
        $error = $data['errcode'] ?? 0;
        if ($error !== 0 && $error !== '0') {
            // The official error table uses 1040029; its example response uses 40029.
            if (in_array($error, [1040029, '1040029', 40029, '40029'], true)) {
                throw new GameException('TAPTAP_CODE_INVALID', 'TapTap 登录凭证无效，请重新登录', 401);
            }
            throw $this->unavailable();
        }
        $openid = $data['openid'] ?? null;
        $sessionKey = $data['session_key'] ?? null;
        $unionid = $data['unionid'] ?? null;
        // IDs are opaque strings; accept all printable non-space ASCII supported
        // by the existing ASCII(128) identity columns without inventing an alphabet.
        if (!is_string($openid) || !preg_match('/^[\x21-\x7e]{1,128}$/D', $openid)
            || !is_string($sessionKey) || $sessionKey === ''
            || ($unionid !== null && (!is_string($unionid) || !preg_match('/^[\x21-\x7e]{1,128}$/D', $unionid)))) {
            throw $this->unavailable();
        }
        return ['provider' => 'taptap_mini', 'app_id' => $appId, 'openid' => $openid, 'unionid' => $unionid];
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
        $caFile = root_path() . 'certs/cacert.pem';
        if (is_file($caFile) && is_readable($caFile)) $options[CURLOPT_CAINFO] = $caFile;
        curl_setopt_array($curl, $options);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if (!is_string($body) || $status !== 200 || strlen($body) > 65536) throw $this->unavailable();
        return $body;
    }

    private function unavailable(): GameException
    {
        return new GameException('TAPTAP_UNAVAILABLE', 'TapTap 登录服务暂时不可用，请稍后重试', 502);
    }
}
