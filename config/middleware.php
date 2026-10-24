<?php
// 中间件配置
return [
    // 别名或分组
    'alias' => [
        'admin_auth' => app\middleware\AdminAuth::class,
        'admin_permission' => app\middleware\AdminPermission::class,
        'wxapp_auth' => app\middleware\WxappAuth::class,
        'wxapp_permission' => app\middleware\WxappPermission::class
    ],
    // 优先级设置，此数组中的中间件会按照数组中的顺序优先执行
    'priority' => [
        app\middleware\WxappAuth::class,
        app\middleware\WxappPermission::class,
    ],
];
