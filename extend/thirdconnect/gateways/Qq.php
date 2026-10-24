<?php
/**
 * PHP Version 5
 * @package thirdconnect
 * @author  Aaron <1020069668@qq.com>
 */
namespace thirdconnect\gateways;

use thirdconnect\base\GatewayBase;

class Qq extends GatewayBase
{
    protected $authorizeUrl = 'https://graph.qq.com/oauth2.0/authorize';
    protected $accessTokenUrl = 'https://graph.qq.com/oauth2.0/token';
    protected $openidUrl = 'https://graph.qq.com/oauth2.0/me';
    protected $userInfoUrl = 'https://graph.qq.com/user/get_user_info';

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
            'response_type' => 'code',
            'client_id' => $this->config['app_id'],
            'redirect_uri' => $this->config['callback'],
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
            $token = $this->getToken();
            $params = ['access_token' => $token['access_token']];
            $response = $this->requestGet($this->openidUrl, $params);
            if (strpos($response, "callback") !== false) {
                $lpos = strpos($response, "(");
                $rpos = strrpos($response, ")");
                $response = substr($response, $lpos + 1, $rpos - $lpos - 1);
            }
            $data = json_decode($response, true);
            if (isset($data['error'])) {
                throw new \Exception($data['error'] . "：" . $data['error_description']);
            }
            $this->token['openid'] = $data['openid'];
        }
        return $this->token['openid'];
    }

    /**
     * 获取原始接口返回的用户信息
     */
    public function getUserinfoRaw()
    {
        $params = [
            'openid' => $this->getOpenid(),
            'oauth_consumer_key' => $this->config['app_id'],
            'access_token' => $this->token['access_token'],
            'format' => 'json'
        ];
        $data = $this->requestGet($this->userInfoUrl, $params);
        $data = json_decode($data, true);
        if ($data['ret'] != 0) {
            throw new \Exception("Error acquiring user information：" . $data['msg']);
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
            'openid' => $openid = $this->getOpenid(),
            'nickname' => $userinfoRaw['nickname'],
            'gender' => $userinfoRaw['gender'] == '男' ? 1 : 2,
            'avatar' => $userinfoRaw['figureurl_qq_2'] ?: $userinfoRaw['figureurl_qq_1']
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
            'client_id' => $this->config['app_id'],
            'client_secret' => $this->config['app_secret'],
            'redirect_uri' => $this->config['callback'],
            'grant_type' => $this->config['grant_type'],
            'code' => $this->getRequestParam('code')
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
        if (strpos($token, "callback") !== false) {
            $lpos = strpos($token, "(");
            $rpos = strrpos($token, ")");
            $token = substr($token, $lpos + 1, $rpos - $lpos - 1);
            $msg = json_decode($token);
            if (isset($msg->error)) {
                throw new \Exception($msg->error . "：" . $msg->error_description);
            }
        }
        $data = array();
        parse_str($token, $data);
        return $data;
    }
}