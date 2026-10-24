<?php
namespace app\wxapp\controller;

use app\common\Dict;

class DictController extends BaseController
{
    public function options()
    {
        $keys = $this->request->param('keys');
        $options = Dict::options($keys);
        $this->success(200, $options);
    }
}
