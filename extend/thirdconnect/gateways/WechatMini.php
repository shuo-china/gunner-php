<?php
/**
 * PHP Version 5
 * @package thirdconnect
 * @author  Aaron <1020069668@qq.com>
 */
namespace thirdconnect\gateways;

use thirdconnect\base\GatewayBase;

class WechatMini extends GatewayBase
{
    protected $accessTokenUrl = 'https://api.weixin.qq.com/sns/jscode2session';

    /**
     * 构造函数
     */
    public function __construct($config = null)
    {
        parent::__construct($config);
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
     * 重载的getTokenRequestParams请求参数
     * @method getTokenRequestParams
     * @return array
     */
    protected function getTokenRequestParams()
    {
        $params = [
            'appid' => $this->config['app_id'],
            'secret' => $this->config['app_secret'],
            'js_code' => $this->getRequestParam('code'),
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