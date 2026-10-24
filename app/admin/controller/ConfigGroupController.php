<?php

namespace app\admin\controller;

use think\facade\Config;
use app\listener\InitConfig;
use app\admin\model\ConfigGroup;
use app\admin\model\ConfigItem;

class ConfigGroupController extends BaseController
{
    protected $middleware = [
        'admin_auth' => [
            'except' => ['initNoAuth']
        ]
    ];

    public function initNoAuth()
    {
        $data = Config::get('sys');
        $this->success(200, [
            'base' => $data['base'],
            'wechat' => [
                'appid' => $data['wechat']['appid'],
            ]
        ]);
    }

    public function init()
    {
        $data = Config::get('sys');
        $this->success(200, [
            'base' => $data['base'],
            'upload' => $data['upload'],
            'pagination' => $data['pagination'],
            'wechat' => [
                'appid' => $data['wechat']['appid'],
            ]
        ]);
    }

    /**
     * 获取配置项
     */
    public function options()
    {
        $list = ConfigGroup::column('name as label,id as value');
        $this->success(200, $list);
    }

    public function list()
    {
        $list = ConfigGroup::select();
        $this->success(200, $list);
    }

    public function pagination()
    {
        $list = ConfigGroup::paginate();
        $this->success(200, $list);
    }

    public function detail()
    {
        $id = $this->request->param('id');
        $info = ConfigGroup::find($id);
        $this->success(200, $info);
    }

    public function create()
    {
        $post = $this->request->post();
        $this->validate($post, 'ConfigGroup');
        ConfigGroup::create($post);
        InitConfig::clear();
        $this->success(201);
    }

    public function update()
    {
        $post = $this->request->post();
        $this->validate($post, 'ConfigGroup');
        ConfigGroup::update($post);
        InitConfig::clear();
        $this->success(201);
    }

    public function delete()
    {
        $id = $this->request->param('id');
        $configGroup = ConfigGroup::find($id);
        ConfigItem::destroy(function ($query) use ($id) {
            $query->where('group_id', $id);
        });
        $configGroup->delete();
        InitConfig::clear();
        $this->success(204);
    }
}
