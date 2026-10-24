<?php
namespace thirdpay\base;

abstract class gatewayBase
{
    /**
     * 配置参数
     */
    protected $config;

    /**
     * 构造函数
     */
    public function __construct($config = null)
    {
        if (!$config) {
            throw new \Exception('config parameter cannot be empty');
        }
        $this->config = array_change_key_case($config, CASE_LOWER);
    }

    /**
     * 生成24位的单号
     */
    public function getOutTradeNo()
    {
        $orderIdMain = date('YmdHis') . rand(10000000, 99999999);
        $orderIdLen = strlen($orderIdMain);
        $orderIdSum = 0;
        for ($i = 0; $i < $orderIdLen; $i++) {
            $orderIdSum += (int) (substr($orderIdMain, $i, 1));
        }
        return $orderIdMain . str_pad((100 - $orderIdSum % 100) % 100, 2, '0', STR_PAD_LEFT);
    }
}