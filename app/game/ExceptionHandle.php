<?php
declare(strict_types=1);

namespace app\game;

use app\common\game\exception\GameException;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\Response;

final class ExceptionHandle extends \think\exception\Handle
{
    public function report(\Throwable $error): void
    {
        if (!$error instanceof GameException && !$error instanceof HttpException && !$error instanceof HttpResponseException) {
            // Credentials, query parameters and SQL values must not enter normal request logs.
            \think\facade\Log::error('Game API failure: ' . get_class($error) . ' at ' . basename($error->getFile()) . ':' . $error->getLine());
        }
    }

    public function render($request, \Throwable $error): Response
    {
        if ($error instanceof HttpResponseException) return $error->getResponse();
        $headers = ['Cache-Control' => 'no-store'];
        if ($error instanceof GameException) {
            $status = $error->status;
            $body = ['code' => $error->errorCode, 'message' => $error->getMessage()];
            $headers += $error->headers;
        } elseif ($error instanceof HttpException) {
            $status = $error->getStatusCode();
            $body = ['code' => $status === 405 ? 'METHOD_NOT_ALLOWED' : 'NOT_FOUND', 'message' => '接口或请求方式不存在'];
        } else {
            $status = 500;
            $body = ['code' => 'SERVER_ERROR', 'message' => '服务暂时不可用，请稍后重试'];
        }
        return json($body, $status, $headers);
    }
}
