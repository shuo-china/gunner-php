<?php

namespace app\admin\controller;

use app\admin\model\Manager;

class ManagerController extends BaseController
{
    protected $middleware = [
        'admin_auth',
        'admin_permission' => [
            'except' => ['currentManager']
        ]
    ];

    public function currentManager()
    {
        $manager = Manager::with([
            'roles' => function ($query) {
                $query->where('status', 1);
            },
            'avatar'
        ])->hidden(['password'])->where('id', $this->request->clientId)->find();

        $this->success(200, $manager);
    }

    public function pagination()
    {
        $param = $this->request->param();

        $map = [];

        if (!empty($param['username'])) {
            $map[] = ['username', '=', $param['username']];
        }

        if (!empty($param['nickname'])) {
            $map[] = ['nickname', '=', $param['nickname']];
        }

        $managers = Manager::with(['roles'])->where($map)->hidden(['password'])
            ->paginate();

        $this->success(200, $managers);
    }

    public function detail()
    {
        $id = $this->request->param('id');
        $manager = Manager::with([
            'roles',
            'avatar'
        ])->hidden(['password'])->where('id', $id)->find();

        $this->success(200, $manager);
    }

    public function create()
    {
        $post = $this->request->post();

        $manager = new Manager;
        $manager->createManager($post);

        $this->success(201);
    }

    public function update()
    {
        $post = $this->request->post();

        $manager = new Manager;
        $manager->updateManager($post);

        $this->success(201);
    }

    public function delete()
    {
        $id = $this->request->param('id');
        $manager = Manager::find($id);

        if ($manager->is_top) {
            $this->error(403, '最高管理员不可以删除', 'RULE_LIMIT');
        }

        $manager->deleteManager();
        $this->success(204);
    }

    public function bindWechat()
    {
        $param = $this->request->param();
        $oauth = \thirdconnect\Oauth::wechat();
        $info = $oauth->getUserinfo();

        Manager::where('id', $param['id'])->update([
            'openid' => $info['openid'],
            'unionid' => $info['unionid'],
        ]);

        $this->success(201);
    }

    public function unBindWechat()
    {
        $param = $this->request->param();

        Manager::where('id', $param['id'])->update([
            'openid' => '',
            'unionid' => '',
        ]);

        $this->success(201);
    }
}
