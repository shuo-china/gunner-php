<?php
namespace app;

use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\Response;
use Throwable;

/**
 * 应用异常处理类
 */
class ExceptionHandle extends Handle
{
    /**
     * 不需要记录信息（日志）的异常类列表
     * @var array
     */
    protected $ignoreReport = [
        HttpException::class,
        HttpResponseException::class,
        ModelNotFoundException::class,
        DataNotFoundException::class,
        ValidateException::class,
    ];

    /**
     * 记录异常信息（包括日志或者其它方式记录）
     *
     * @access public
     * @param  Throwable $exception
     * @return void
     */
    public function report(Throwable $exception): void
    {
        if (!$this->isIgnoreReport($exception)) {
            $data = [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'issue' => $this->getMessage($exception),
                'error_code' => $this->getCode($exception),
                'request_ip' => $this->app->request->ip(),
                'request_url' => $this->app->request->url(),
                'request_method' => $this->app->request->method(),
                'request_time' => $this->app->request->time(),
                'create_time' => time(),
                'update_time' => time()
            ];
            try {
                \think\facade\Db::name('exception')->insert($data);
            } catch (Throwable $e) {

            }
        }

        // 使用内置的方式记录异常日志
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @access public
     * @param \think\Request   $request
     * @param Throwable $e
     * @return Response
     */
    public function render($request, Throwable $e): Response
    {
        // 添加自定义异常处理机制
        if ($this->needCustomHandle($request, $e)) {
            return $this->customConvertExceptionToResponse($e);
        }

        // 其他错误交给系统处理
        return parent::render($request, $e);
    }

    /**
     * @access protected
     * @param Request $request
     * @param Throwable $exception
     * @return bool
     */
    protected function needCustomHandle($request, Throwable $exception): bool
    {
        if (!$exception instanceof HttpResponseException && $request->isJson()) {
            return true;
        }
        return false;
    }

    /**
     * 获取HTTP状态码
     * @param Throwable $exception
     * @return int
     */
    protected function getStatusCode(Throwable $exception): int
    {
        if ($exception instanceof HttpException) {
            return $exception->getStatusCode();
        } elseif ($exception instanceof ValidateException) {
            return 422;
        } else {
            return 500;
        }
    }

    /**
     * @access protected
     * @param Throwable $exception
     * @return Response
     */
    protected function customConvertExceptionToResponse(Throwable $exception): Response
    {
        $statusCode = $this->getStatusCode($exception);

        $response = Response::create($this->customConvertExceptionToArray($exception, $statusCode), 'json');

        if ($exception instanceof HttpException) {
            $response->header($exception->getHeaders());
        }
        // ->header(['Access-Control-Allow-Origin'  => '*'])
        return $response->code($statusCode);
    }

    /**
     * 获取错误信息
     * @param Throwable $exception
     * @param int $statusCode
     * @return array
     */
    protected function getErrorInfo(Throwable $exception, int $statusCode): array
    {
        switch ($statusCode) {
            case 404:
                $error = [
                    'code' => 'NOT_FOUND',
                    'message' => '请求的资源不存在'
                ];
                break;
            case 422:
                $error = [
                    'code' => 'PARAM_ERROR',
                    'message' => $exception->getMessage()
                ];
                break;
            case 500:
                $error = [
                    'code' => 'SERVER_ERROR',
                    'message' => '服务端错误'
                ];
                break;
            default:
                $error = [
                    'code' => 'UNKNOWN_ERROR',
                    'message' => '未知错误'
                ];
                break;
        }
        return $error;
    }

    /**
     * 收集异常数据
     * @param Throwable $exception
     * @return array
     */
    protected function customConvertExceptionToArray(Throwable $exception, int $statusCode): array
    {
        $data = $this->getErrorInfo($exception, $statusCode);

        if ($this->app->isDebug()) {
            // 调试模式，获取详细的错误信息
            $data['detail'] = [
                'issue' => $this->getMessage($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ];
        }

        return $data;
    }
}
