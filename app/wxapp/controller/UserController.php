<?php

namespace app\wxapp\controller;

use app\wxapp\model\User;
use app\wxapp\model\UserWechatMini;
use app\middleware\annotation\WxappPermission;

class UserController extends BaseController
{
    protected $middleware = [
        'wxapp_auth:guest' => [
            'only' => [
                'bindMobile'
            ],
        ],
        'wxapp_auth:bound' => [
            'except' => [
                'bindMobile'
            ],
        ],
        'wxapp_permission',
    ];

    #[WxappPermission(['teacher'])]
    public function currentUser()
    {
        $user = User::where('id', $this->request->clientId)->find();

        $this->success(200, $user);
    }

    public function bindMobile()
    {
        $code = $this->request->param('code');
        $mobileInfo = $this->getWechatMiniMobile($code);
        $purePhoneNumber = $mobileInfo['phone_info']['purePhoneNumber'];

        $user = User::where('mobile', $purePhoneNumber)->find();
        if (!$user) {
            $user = User::create([
                'nickname' => $purePhoneNumber,
                'mobile' => $purePhoneNumber,
            ]);
        } else {
            $userWxapp = UserWechatMini::where('user_id', $user->id)->find();
            if ($userWxapp) {
                $this->error(401, '该手机号已被绑定', 'BIND_MOBILE_EXISTS');
            }
        }

        UserWechatMini::where('id', $this->request->clientId)->update([
            'user_id' => $user->id,
        ]);

        $this->success(201);
    }

    public function unBindMobile()
    {
        UserWechatMini::where('id', $this->request->userWxappId)->update([
            'user_id' => null,
        ]);

        $this->success(201);
    }

    /**
     * 获取手机号码
     */
    protected function getWechatMiniMobile($code)
    {
        $params = [
            'appid' => config('sys.wechat_mini.appid'),
            'secret' => config('sys.wechat_mini.appsecret'),
            'grant_type' => 'client_credential',
            'force_refresh' => false,
        ];

        $requestTokenUrl = 'https://api.weixin.qq.com/cgi-bin/stable_token';

        $access_token = $this->posturl($requestTokenUrl, $params);

        $access_token = $access_token['access_token'];

        $requestMobile = $this->posturl('https://api.weixin.qq.com/wxa/business/getuserphonenumber?access_token=' . $access_token, ['code' => $code]);

        if ($requestMobile['errcode'] != 0) {
            $this->error(401, $requestMobile['errmsg'], 'WECHAT_ERROR');
        }

        return $requestMobile;
    }

    protected function posturl($url, $data)
    {
        $data = json_encode($data);
        $headerArray = array("Content-type:application/json;charset='utf-8'", "Accept:application/json");
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headerArray);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $output = curl_exec($curl);
        curl_close($curl);
        return json_decode($output, true);
    }
}
