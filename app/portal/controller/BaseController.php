<?php
namespace app\portal\controller;

use app\portal\PortalSend;
use app\common\BaseController as CommonBaseController;

class BaseController extends CommonBaseController
{
    use PortalSend;

    protected function initialize()
    {
        parent::initialize();
    }
}