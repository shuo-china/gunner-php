<?php
declare(strict_types=1);

return [
    'session_ttl' => (int) env('game.session_ttl', 86400),
    'login_rate' => (int) env('game.login_rate', 30),
    'save_namespace' => (string) env('game.save_namespace', 'production'),
    'reward_timezone' => (string) env('game.reward_timezone', 'Asia/Shanghai'),
    'node_binary' => (string) env('game.node_binary', 'node'),
    'wechat' => [
        'app_id' => (string) env('game_wechat.app_id', ''),
        'app_secret' => (string) env('game_wechat.app_secret', ''),
    ],
];
