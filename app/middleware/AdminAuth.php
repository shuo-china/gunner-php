<?php

declare(strict_types=1);

namespace app\middleware;

use app\common\Send;
use app\common\Auth;

class AdminAuth
{
    use Send;

    /**
     * 允许的请求方法
     * @var array
     */
    protected $allowRequestMethods = ['get', 'post', 'put', 'patch', 'delete', 'options'];

    /**
     * 处理请求
     * @param \think\Request $request
     * @param \Closure $next
     */
    public function handle($request, \Closure $next)
    {
        $this->authenticateRequest($request);

        return $next($request);
    }

    protected function authenticateRequest($request): void
    {
        // 检测请求方法类型是否允许
        if (!in_array(strtolower($request->method()), $this->allowRequestMethods)) {
            $this->error(405, '请求的方法被禁止', 'METHOD_NOT_ALLOWED');
        }

        // 解析token
        $payload = Auth::authenticate();

        if (app('http')->getName() !== $payload['role']) {
            $this->error(403, '没有权限', 'NO_AUTH');
        }

        $request->clientId = $payload['sub'];
        $request->clientInfo = $payload['info'];
    }
}
