<?php

namespace app\admin\controller;

use app\common\Dict;
use app\admin\model\DictType;
use app\admin\model\DictItem;

class DictTypeController extends BaseController
{
    public function options()
    {
        $keys = $this->request->param('keys');
        $options = Dict::options($keys);
        $this->success(200, $options);
    }

    public function list()
    {
        $list = DictType::select();
        $this->success(200, $list);
    }

    public function detail()
    {
        $id = $this->request->param('id');
        $info = DictType::where('id', $id)->find();
        $this->success(200, $info);
    }

    public function create()
    {
        $post = $this->request->post();
        $this->validate($post, 'DictType');

        DictType::create($post);
        Dict::clear();
        $this->success(201);
    }

    public function update()
    {
        $post = $this->request->post();
        $this->validate($post, 'DictType');

        DictType::update($post);
        Dict::clear();
        $this->success(201);
    }

    public function delete()
    {
        $id = $this->request->param('id');
        $dictType = DictType::find($id);

        DictItem::destroy(function ($query) use ($id) {
            $query->where('type_id', $id);
        });
        $dictType->delete();

        Dict::clear();
        $this->success(204);
    }
}
