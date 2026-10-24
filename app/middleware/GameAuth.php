<?php
declare(strict_types=1);

namespace app\middleware;

use app\common\game\service\GameSessionService;

final class GameAuth
{
    public function handle($request, \Closure $next)
    {
        $request->gameSession = (new GameSessionService())->authenticate((string) $request->header('Authorization', ''));
        return $next($request);
    }
}
