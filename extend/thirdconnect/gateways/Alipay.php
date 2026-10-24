<?php
/**
 * PHP Version 5
 * @package thirdconnect
 * @author  Aaron <1020069668@qq.com>
 */
namespace thirdconnect\gateways;

use thirdconnect\base\GatewayBase;

class Alipay extends GatewayBase
{
    protected $authorizeUrl = 'https://openauth.alipay.com/oauth2/publicAppAuthorize.htm';
    protected $accessTokenUrl = 'https://openapi.alipay.com/gateway.do';
    protected $userInfoUrl = 'https://openapi.alipay.com/gateway.do';

    /**
     * 构造函数
     */
    public function __construct($config = null)
    {
        parent::__construct($config);
    }

    /**
     * 跳转到登录界面
     */
    public function login()
    {
        $params = [
            'app_id' => $this->config['app_id'],
            'redirect_uri' => $this->config['callback'],
            'scope' => 'auth_user',
            'state' => $this->buildState()
        ];

        $redirectUrl = $this->authorizeUrl . '?' . http_build_query($params);
        header("Location:$redirectUrl");
        exit();
    }

    /**
     * 获取当前授权用户的openid标识
     */
    public function getOpenid()
    {
        if (!isset($this->token['openid']) || !$this->token['openid']) {
            $this->getToken();
        }
        return $this->token['openid'];
    }

    /**
     * 获取原始接口返回的用户信息
     */
    public function getUserinfoRaw()
    {
        $token = $this->getToken();
        $params = [
            'app_id' => $this->config['app_id'],
            'method' => 'alipay.user.info.share',
            'charset' => 'utf-8',
            'sign_type' => 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'auth_token' => $token['access_token']
        ];
        $params['sign'] = $this->rsaSign($params);
        $data = $this->requestPost($this->userInfoUrl, $params);
        $data = mb_convert_encoding($data, 'utf-8', 'gbk');
        $data = json_decode($data, true);
        $data = $data['alipay_user_info_share_response'];
        if ($data['code'] !== '10000') {
            throw new \Exception($data['code'] . "：" . $data['msg']);
        }
        return $data;
    }

    /**
     * 获取格式化后的用户信息
     */
    public function getUserinfo()
    {
        $userinfoRaw = $this->getUserinfoRaw();

        $userinfo = [
            'openid' => $this->token['openid'],
            'nickname' => $userinfoRaw['nick_name'],
            'gender' => $userinfoRaw['gender'] == "m" ? 1 : 2,
            'avatar' => $userinfoRaw['avatar']
        ];
        return $userinfo;
    }

    /**
     * 重载的getTokenRequestParams请求参数
     * @method getTokenRequestParams
     * @return array
     */
    protected function getTokenRequestParams()
    {
        $params = [
            'app_id' => $this->config['app_id'],
            'method' => 'alipay.system.oauth.token',
            'charset' => 'utf-8',
            'sign_type' => 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'grant_type' => $this->config['grant_type'],
            'code' => $this->getRequestParam('auth_code')
        ];
        $params['sign'] = $this->rsaSign($params);
        return $params;
    }

    /**
     * 解析access_token方法请求后的返回值
     * @method parseToken
     * @param  $token 获取access_token的方法的返回值
     */
    protected function parseToken($token)
    {
        $token = mb_convert_encoding($token, 'utf-8', 'gbk');
        $data = json_decode($token, true);
        if (isset($data['error_response'])) {
            $error = $data['error_response'];
            throw new \Exception($error['code'] . "：" . $error['msg']);
        }
        $data = $data['alipay_system_oauth_token_response'];
        $data['openid'] = $data['user_id'];
        return $data;
    }

    /**
     * RSA签名方法
     * @method rsaSign
     * @param  array $data 要签名的数据
     */
    protected function rsaSign(array $data = [])
    {
        if (!isset($this->config['private_key']) || !$this->config['private_key']) {
            throw new \Exception("config parameter 'PRIVATE_KEY' cannot be empty");
        }
        $privateKey = "-----BEGIN RSA PRIVATE KEY-----\n" .
            wordwrap($this->config['private_key'], 64, "\n", true) .
            "\n-----END RSA PRIVATE KEY-----";
        foreach ($data as $k => $v) {
            if ($k == 'sign' || $v == "" || is_array($v)) {
                unset($data[$k]);
            }
        }
        ksort($data);
        $data = urldecode(http_build_query($data));
        openssl_sign($data, $sign, $privateKey, OPENSSL_ALGO_SHA256);
        $sign = base64_encode($sign);
        return $sign;
    }
}