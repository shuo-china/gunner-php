<?php

namespace app\admin\controller;

use app\listener\InitConfig;
use app\admin\model\ConfigItem;

class ConfigItemController extends BaseController
{
    /**
     * 获取配置项列表
     */
    public function list()
    {
        $map = [];

        $groupId = $this->request->param('group_id');
        if ($groupId) {
            $map[] = ['group_id', '=', $groupId];
        }

        $list = ConfigItem::where($map)->select()->each(function ($item) {
            $item['value_raw'] = $item->getData('value');
        });

        $this->success(200, $list);
    }

    public function detail()
    {
        $id = $this->request->param('id');
        $info = ConfigItem::where('id', $id)->find();
        $this->success(200, $info);
    }

    /**
     * 创建配置项
     */
    public function create()
    {
        $post = $this->request->post();
        $this->validate($post, 'ConfigItem');

        ConfigItem::create($post);
        InitConfig::clear();
        $this->success(201);
    }

    /**
     * 创建配置项
     */
    public function update()
    {
        $post = $this->request->post();
        $this->validate($post, 'ConfigItem');

        ConfigItem::update($post);
        InitConfig::clear();
        $this->success(201);
    }

    /**
     * 更新配置项的值
     */
    public function updateValue()
    {
        $post = $this->request->post();
        ConfigItem::update($post);
        InitConfig::clear();
        $this->success(201);
    }

    public function delete()
    {
        $id = $this->request->param('id');
        ConfigItem::destroy(function ($query) use ($id) {
            $query->where('id', $id);
        });
        InitConfig::clear();
        $this->success(204);
    }

    public function typeOptions()
    {
        $result = [
            ['label' => '单行文本', 'value' => 'text'],
            ['label' => '多行文本', 'value' => 'textarea'],
            ['label' => '数字', 'value' => 'number'],
            ['label' => '下拉框', 'value' => 'select'],
            ['label' => '复选框', 'value' => 'checkbox'],
            ['label' => '开关', 'value' => 'switch'],
            ['label' => '标签', 'value' => 'tags'],
            ['label' => '单张图片', 'value' => 'image'],
            ['label' => '多张图片', 'value' => 'images'],
            ['label' => '单个文件', 'value' => 'file'],
            ['label' => '多个文件', 'value' => 'files'],
        ];
        $this->success(200, $result);
    }
}
