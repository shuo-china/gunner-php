<?php

namespace app\admin\controller;

use app\admin\AdminSend;
use app\common\BaseController as CommonBaseController;

class BaseController extends CommonBaseController
{
    use AdminSend;

    protected $middleware = ['admin_auth', 'admin_permission'];

    protected function initialize()
    {
        parent::initialize();
    }
}
