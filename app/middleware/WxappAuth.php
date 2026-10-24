<?php

declare(strict_types=1);

namespace app\middleware;

class WxappAuth extends AdminAuth
{
    protected $levels = [
        'guest' => 1,
        'bound' => 2,
    ];

    /**
     * 处理请求
     * @param \think\Request $request
     * @param \Closure $next
     */
    public function handle($request, \Closure $next, $level = 'guest')
    {
        $this->authenticateRequest($request);

        if ($this->levels[$level] > $this->levels[$request->clientInfo['level']]) {
            $this->error(403, '没有权限', 'NO_AUTH');
        }

        if ($this->levels[$request->clientInfo['level']] >= $this->levels['guest']) {
            $request->userWxappId = $request->clientInfo['user_wxapp_info']['id'];
            $request->userWxappInfo = $request->clientInfo['user_wxapp_info'];
        }

        if ($this->levels[$request->clientInfo['level']] >= $this->levels['bound']) {
            $request->userId = $request->clientInfo['user_info']['id'];
            $request->userInfo = $request->clientInfo['user_info'];
        }

        return $next($request);
    }
}
