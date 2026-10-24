<?php

return [
    // 微信支付配置 
    'Wechat' => [
        // 商户号
        'MERCHANT_ID' => config('sys.wechat_pay.merchant_id'),
        // 商户证书序列号
        'MERCHANT_CERT_SERIAL' => config('sys.wechat_pay.merchant_cert_serial'),
        // 商户API私钥
        'MERCHANT_PRIVATE_KEY' => config('sys.wechat_pay.merchant_private_key') ? 'file://' . app()->getRootPath() . 'public' . config('sys.wechat_pay.merchant_private_key')->getData('path') : '',
        // 微信支付公钥ID
        'PLATFORM_PUBLIC_KEY_ID' => config('sys.wechat_pay.platform_public_key_id'),
        // 微信支付公玥
        'PLATFORM_PUBLIC_KEY' => config('sys.wechat_pay.platform_public_key') ? 'file://' . app()->getRootPath() . 'public' . config('sys.wechat_pay.platform_public_key')->getData('path') : '',
        // 平台证书序列号
        'PLATFORM_CERT_SERIAL' => config('sys.wechat_pay.platform_cert_serial'),
        // 平台证书
        'PLATFORM_CERT' => config('sys.wechat_pay.platform_cert') ? 'file://' . app()->getRootPath() . 'public' . config('sys.wechat_pay.platform_cert')->getData('path') : '',
        // APIv3密钥
        'API_V3_KEY' => config('sys.wechat_pay.api_v3_key'),
        // 回调地址
        'NOTIFY_URL' => config('sys.wechat_pay.notify_url'),
    ],
];