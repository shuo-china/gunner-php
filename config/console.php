<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        'game:migrate' => app\command\GameMigrate::class,
        'game:prune' => app\command\GamePrune::class,
    ],
];
