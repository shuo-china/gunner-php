<?php

return [
    // 腾讯QQ登录配置 
    'Qq' => [
        'APPID' => '',
        'APPSECRET' => '',
        'CALLBACK' => ''
    ],

    // 微信登录配置 
    'Wechat' => [
        'APPID' => config('sys.wechat.appid'),
        'APPSECRET' => config('sys.wechat.appsecret'),
        'CALLBACK' => config('sys.wechat.callback')
    ],

    // 微信H5登录配置
    'WechatH5' => [
        'APPID' => '',
        'APPSECRET' => '',
        'CALLBACK' => ''
    ],

    // 微信小程序登录配置
    'WechatMini' => [
        'APPID' => config('sys.wechat_mini.appid'),
        'APPSECRET' => config('sys.wechat_mini.appsecret')
    ],

    // 支付宝登录配置 
    'Alipay' => [
        'APPID' => '',
        'CALLBACK' => '',
        'PRIVATE_KEY' => ''
    ],
];