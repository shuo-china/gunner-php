<?php

namespace app\admin\controller;

use app\common\Dict;
use app\admin\model\DictItem;

class DictItemController extends BaseController
{
    public function list()
    {
        $dictTypeId = $this->request->param('type_id');
        $list = DictItem::where('type_id', $dictTypeId)->order('id asc')->select();
        $this->success(200, $list);
    }

    public function detail()
    {
        $id = $this->request->param('id');
        $info = DictItem::where('id', $id)->find();
        $this->success(200, $info);
    }

    public function create()
    {
        $post = $this->request->post();
        $this->validate($post, 'DictItem');

        DictItem::create($post);
        Dict::clear();
        $this->success(201);
    }

    public function update()
    {
        $post = $this->request->post();
        $this->validate($post, 'DictItem');

        DictItem::update($post);
        Dict::clear();
        $this->success(201);
    }

    public function delete()
    {
        $id = $this->request->param('id');
        DictItem::destroy(function ($query) use ($id) {
            $query->where('id', $id);
        });
        Dict::clear();
        $this->success(204);
    }
}
