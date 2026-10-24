<?php

namespace app\portal\controller;

class CallbackController extends BaseController
{
    public function wechatNotify()
    {
        \think\facade\Log::write('NotifyInput：' . file_get_contents('php://input'));

        $headers = getallheaders();
        \think\facade\Log::write('NotifyHeaders：' . json_encode($headers));

        $pay = \thirdpay\Pay::wechat();
        $body = $pay->getNotifyBody();

        \think\facade\Log::write('notifyBodu：' . json_encode($body));

        // $this->success(200);
    }
}