<?php

namespace app\wxapp\controller;

use app\wxapp\WxappSend;
use app\common\BaseController as CommonBaseController;

class BaseController extends CommonBaseController
{
    use WxappSend;

    protected $middleware = ['wxapp_auth:bound', 'wxapp_permission'];

    protected function initialize()
    {
        parent::initialize();
    }
}
