<?php

namespace app\admin\controller;

use app\common\Auth;
use app\common\Password;
use app\admin\model\Manager;
use think\middleware\annotation\RateLimit;

class AccessController extends BaseController
{
    protected $middleware = [];

    #[RateLimit(rate: "5/m")]
    public function createTokenByPassword()
    {
        $post = $this->request->post();

        $this->validate($post, 'Access.password');

        $manager = Manager::where('username', $post['username'])->find();

        if (empty($manager) || !Password::verify($post['password'], $manager->password)) {
            $this->error(401, '无权限登录或已禁用', 'LOGIN_FAIL');
        }

        if (!$manager->status) {
            $this->error(403, '该账号已被禁用', 'NOT_AUTH');
        }

        $managerInfo = [
            'id' => $manager->id,
            'nickname' => $manager->nickname
        ];

        $accessToken = Auth::setAccessToken($manager->id, $managerInfo);
        $this->app->event->trigger('ManagerLoginAfter', $manager);

        $this->success(201, $accessToken);
    }

    public function createTokenByWechat()
    {
        $param = $this->request->param();

        $this->validate($param, 'Access.wechat');

        $oauth = \thirdconnect\Oauth::wechat();
        $info = $oauth->getUserinfo();

        $manager = Manager::where('openid', $info['openid'])->find();

        if (empty($manager)) {
            $this->error(401, '无权限登录或已禁用', 'LOGIN_FAIL');
        }

        if (!$manager->status) {
            $this->error(403, '该账号已被禁用', 'NOT_AUTH');
        }

        $managerInfo = [
            'id' => $manager->id,
            'nickname' => $manager->nickname
        ];

        $accessToken = Auth::setAccessToken($manager->id, $managerInfo);
        $this->app->event->trigger('ManagerLoginAfter', $manager);

        $this->success(201, $accessToken);
    }
}
