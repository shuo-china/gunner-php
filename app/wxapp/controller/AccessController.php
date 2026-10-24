<?php

namespace app\wxapp\controller;

use app\common\Auth;
use app\wxapp\model\User;
use app\wxapp\model\UserWechatMini;

class AccessController extends BaseController
{
    protected $middleware = [];

    public function createAccessToken()
    {
        $oauth = \thirdconnect\Oauth::wechat_mini();
        $result = $oauth->getToken();
        $userWxapp = UserWechatMini::where('wechat_mini_openid', $result['openid'])->find();

        if (empty($userWxapp)) {
            $userWxapp = UserWechatMini::create([
                'wechat_mini_openid' => $result['openid'],
                'wechat_unionid' => isset($result['unionid']) ? $result['unionid'] : '',
            ]);
        }

        $userWxappInfo = [
            'id' => $userWxapp->id
        ];

        if ($userWxapp->user_id) {
            $user = User::where('id', $userWxapp->user_id)->find();
            if (!$user->status) {
                $this->error(403, '该账号已被禁用', 'NOT_AUTH');
            }

            $userInfo = [
                'id' => $user->id,
                'roles' => ['teacher', 'student'],
            ];
            $accessToken = Auth::setAccessToken($user->id, [
                'level' => 'bound',
                'user_info' => $userInfo,
                'user_wxapp_info' => $userWxappInfo,
            ]);
            $this->app->event->trigger('UserLoginAfter', $userWxapp);
            $this->app->event->trigger('UserLoginAfter', $user);
            $this->success(201, [
                'level' => 'bound',
                'token_info' => $accessToken,
            ]);
        } else {
            $accessToken = Auth::setAccessToken($userWxapp->id, [
                'level' => 'guest',
                'user_wxapp_info' => $userWxappInfo,
            ]);
            $this->app->event->trigger('UserLoginAfter', $userWxapp);
            $this->success(201, [
                'level' => 'guest',
                'token_info' => $accessToken,
            ]);
        }
    }
}
