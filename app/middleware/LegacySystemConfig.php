<?php
declare(strict_types=1);

namespace app\middleware;

class LegacySystemConfig
{
    public function handle($request, \Closure $next)
    {
        (new \app\listener\InitConfig())->handle();
        return $next($request);
    }
}
