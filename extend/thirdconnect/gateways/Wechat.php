<?php
/**
 * PHP Version 5
 * @package thirdconnect
 * @author  Aaron <1020069668@qq.com>
 */
namespace thirdconnect\gateways;

use thirdconnect\base\GatewayBase;

class Wechat extends GatewayBase
{
    protected $authorizeUrl = 'https://open.weixin.qq.com/connect/qrconnect';
    protected $accessTokenUrl = 'https://api.weixin.qq.com/sns/oauth2/access_token';
    protected $userInfoUrl = 'https://api.weixin.qq.com/sns/userinfo';

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
            'appid' => $this->config['app_id'],
            'redirect_uri' => $this->config['callback'],
            'response_type' => 'code',
            'scope' => 'snsapi_login',
            'state' => $this->buildState()
        ];

        $redirectUrl = $this->authorizeUrl . '?' . http_build_query($params) . '#wechat_redirect';
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
            'access_token' => $token['access_token'],
            'openid' => $token['openid'],
            'lang' => 'zh_CN'
        ];
        $data = $this->requestGet($this->userInfoUrl, $params);
        $data = json_decode($data, true);

        if (isset($data['errcode'])) {
            throw new \Exception($data['errcode'] . "：" . $data['errmsg']);
        } else {
            return $data;
        }
    }

    /**
     * 获取格式化后的用户信息
     */
    public function getUserinfo()
    {
        $userinfoRaw = $this->getUserinfoRaw();
        $avatar = $userinfoRaw['headimgurl'];
        if ($avatar) {
            $avatar = \preg_replace('~\/\d+$~', '/0', $avatar);
        }

        $userinfo = [
            'openid' => $this->getOpenid(),
            'unionid' => $userinfoRaw['unionid'],
            'nickname' => $userinfoRaw['nickname'],
            'gender' => $userinfoRaw['sex'],
            'avatar' => $avatar,
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
            'appid' => $this->config['app_id'],
            'secret' => $this->config['app_secret'],
            'code' => $this->getRequestParam('code'),
            'grant_type' => $this->config['grant_type']
        ];
        return $params;
    }

    /**
     * 解析access_token方法请求后的返回值
     * @method parseToken
     * @param  $token 获取access_token的方法的返回值
     */
    protected function parseToken($token)
    {
        $data = json_decode($token, true);
        if (isset($data['errcode'])) {
            throw new \Exception($data['errcode'] . "：" . $data['errmsg']);
        } else {
            return $data;
        }
    }
}