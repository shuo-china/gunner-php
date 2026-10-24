<?php

/**
 * PHP Version 5
 * @package thirdconnect
 * @author  Aaron <1020069668@qq.com>
 */

namespace thirdconnect\base;

abstract class gatewayBase
{
    /**
     * 配置参数
     */
    protected $config;

    /**
     * 当前时间戳
     */
    protected $timestamp;

    /**
     * 第三方Token信息
     */
    protected $token = null;

    /**
     * 第三方Token获取地址
     */
    protected $accessTokenUrl;

    public function __construct($config = null)
    {
        $this->config = [
            'app_id' => $config['APPID'],
            'app_secret' => isset($config['APPSECRET']) ? $config['APPSECRET'] : '',
            'private_key' => isset($config['PRIVATE_KEY']) ? $config['PRIVATE_KEY'] : '',
            'callback' => isset($config['CALLBACK']) ? $config['CALLBACK'] : '',
            'grant_type' => 'authorization_code'
        ];
        $this->timestamp = time();
    }

    /**
     * 获取当前授权用户的openid标识
     */
    abstract function getOpenid();

    /**
     * 获取Token请求参数
     */
    abstract protected function getTokenRequestParams();

    /**
     * 解析access_token方法请求后的返回值
     * @param $token 请求第三方平台的返回值
     */
    abstract protected function parseToken($token);

    /**
     * 获取保存在SESSION中state参数的key值
     */
    protected function getStateSessionKey()
    {
        return strtoupper(basename(str_replace('\\', '/', get_called_class()))) . 'STATE';
    }

    /**
     * 生成state参数，用于第三方应用防止CSRF攻击
     */
    protected function buildState()
    {
        $state = md5(uniqid(rand(), true));
        $sessionKey = $this->getStateSessionKey();
        session($sessionKey, $state);
        return $state;
    }

    /**
     * 验证state参数
     */
    protected function checkState()
    {
        $sessionKey = $this->getStateSessionKey();
        if (empty(session($sessionKey) || (session($sessionKey)) !== $_GET['state'])) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * 执行GET请求操作
     * @method requestGet
     * @param string $url 请求的url地址
     * @param array $params 请求参数
     * @return string
     */
    protected function requestGet($url, $params = [])
    {
        $curl = curl_init();
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        $data = curl_exec($curl);
        curl_close($curl);
        return $data;
    }

    /**
     * 执行POST请求操作
     * @method requestPost
     * @param string $url 请求的url地址
     * @param array $params 请求参数
     * @return string
     */
    protected function requestPost($url, $params = [])
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($curl, CURLOPT_POST, TRUE);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $params);
        $data = curl_exec($curl);
        curl_close($curl);
        return $data;
    }

    /**
     * 获取getTokenRaw
     * @method getToken
     * @return string
     */
    protected function getTokenRaw()
    {
        $params = $this->getTokenRequestParams();
        return $this->requestPost($this->accessTokenUrl, $params);
    }

    /**
     * 获取token信息
     * @method getToken
     * @return array
     */
    public function getToken()
    {
        if (empty($this->token)) {
            $token = $this->getTokenRaw();
            $this->token = $this->parseToken($token);
        }
        return $this->token;
    }

    protected function getRequestParam($key, $default = '')
    {
        if (isset($_REQUEST[$key])) {
            return $_REQUEST[$key];
        }

        $raw = file_get_contents('php://input');
        if ($raw) {
            $json = json_decode($raw, true);
            if (is_array($json) && array_key_exists($key, $json)) {
                return $json[$key];
            }
        }

        return $default;
    }
}
