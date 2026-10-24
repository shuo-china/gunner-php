<?php

namespace app\common;

use think\Response;
use think\exception\HttpResponseException;

trait Send
{
    /**
     * 成功响应
     * @param int   $statusCode   HTTP状态码
     * @param array | string $responseData 响应数据
     * @param array $header       设置响应头
     */
    public static function success($statusCode, $responseData = '', $header = [])
    {
        self::response($responseData, $statusCode, $header);
    }

    /**
     * 失败响应
     * @param int    $statusCode HTTP状态码
     * @param string $message    错误消息
     * @param string $code       错误代码
     * @param array  $detail     错误详情
     * @param array  $header     设置响应头
     */
    public static function error($statusCode, $message, $code = '', $detail = [], $header = [])
    {
        $responseData = [
            'code' => $code,
            'message' => $message
        ];

        if (!empty($detail)) {
            $responseData['detail'] = $detail;
        }

        self::response($responseData, $statusCode, $header);
    }

    /**
     * 响应
     * @param mixed $responseData 响应数据
     * @param int   $statusCode   HTTP状态码
     * @param array $header       设置响应头
     */
    public static function response($responseData, $statusCode, $header = [])
    {
        $response = Response::create($responseData, 'json', (int) $statusCode)->header($header);
        throw new HttpResponseException($response);
    }
}
