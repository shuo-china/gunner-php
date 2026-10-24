<?php
namespace thirdpay\gateways;

use thirdpay\base\GatewayBase;
use WeChatPay\Builder;
use WeChatPay\Crypto\Rsa;
use WeChatPay\Formatter;
use WeChatPay\Crypto\AesGcm;

class Wechat extends GatewayBase
{
    protected $instance = null;

    public function __construct($config = null)
    {
        parent::__construct($config);

        $certs = [];
        if ($this->config['platform_public_key_id'] && $this->config['platform_public_key']) {
            $certs[$this->config['platform_public_key_id']] = Rsa::from($this->config['platform_public_key'], Rsa::KEY_TYPE_PUBLIC);
        }
        if ($this->config['platform_cert_serial'] && $this->config['platform_cert']) {
            $certs[$this->config['platform_cert_serial']] = Rsa::from($this->config['platform_cert'], Rsa::KEY_TYPE_PUBLIC);
        }

        $this->instance = Builder::factory([
            'mchid' => $this->config['merchant_id'],
            'serial' => $this->config['merchant_cert_serial'],
            'privateKey' => $this->config['merchant_private_key'],
            'certs' => $certs
        ]);
    }

    public function native(array $params = [])
    {
        $params = array_merge([
            'mchid' => $this->config['merchant_id'],
            'notify_url' => $this->config['notify_url'],
        ], $params);

        if (!isset($params['out_trade_no'])) {
            $params['out_trade_no'] = $this->getOutTradeNo();
        }

        $resp = $this->instance
            ->chain('v3/pay/transactions/native')
            ->post([
                'json' => $params
            ]);

        $body = $resp->getBody();
        $body = json_decode($body, true);

        return $body;
    }

    public function getNotifyBody()
    {
        // 1. 读取 headers 和 body
        $headers = array_change_key_case(getallheaders(), CASE_LOWER);
        $inWechatpaySignature = $headers['wechatpay-signature'] ?? '';
        $inWechatpayTimestamp = $headers['wechatpay-timestamp'] ?? '';
        $inWechatpayNonce = $headers['wechatpay-nonce'] ?? '';
        $inWechatpaySerial = $headers['wechatpay-serial'] ?? '';
        $inBody = file_get_contents('php://input');
        $apiv3Key = $this->config['api_v3_key'];

        // 2. 加载微信支付公钥或平台证书
        $platformInstance = null;
        if ($inWechatpaySerial === $this->config['platform_public_key_id']) {
            $platformInstance = Rsa::from($this->config['platform_public_key'], Rsa::KEY_TYPE_PUBLIC);
        } else if ($inWechatpaySerial === $this->config['platform_cert_serial']) {
            $platformInstance = Rsa::from($this->config['platform_cert'], Rsa::KEY_TYPE_PUBLIC);
        }

        if (!$platformInstance) {
            throw new \Exception('微信支付平台公钥或平台证书配置错误');
        }

        // 3. 验签 + 时间校验
        $timeOffsetStatus = 300 >= abs(Formatter::timestamp() - (int) $inWechatpayTimestamp);

        $verifiedStatus = Rsa::verify(
            Formatter::joinedByLineFeed($inWechatpayTimestamp, $inWechatpayNonce, $inBody),
            $inWechatpaySignature,
            $platformInstance
        );

        if (!$timeOffsetStatus || !$verifiedStatus) {
            throw new \Exception('签名验证失败');
        }

        // 转换通知的JSON文本消息为PHP Array数组
        $inBodyArray = json_decode($inBody, true);
        // 使用PHP7的数据解构语法，从Array中解构并赋值变量
        [
            'resource' => [
                'ciphertext' => $ciphertext,
                'nonce' => $nonce,
                'associated_data' => $aad
            ]
        ] = $inBodyArray;

        // 加密文本消息解密
        $inBodyResource = AesGcm::decrypt($ciphertext, $apiv3Key, $nonce, $aad);
        // 把解密后的文本转换为PHP Array数组
        $inBodyResourceArray = json_decode($inBodyResource, true);
        return $inBodyResourceArray;
    }
}