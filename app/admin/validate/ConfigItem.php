<?php
namespace app\admin\validate;

use think\Validate;

class ConfigItem extends Validate
{
    protected $rule = [
        'type|配置类型' => 'require',
        'name|配置名称' => 'require',
        'key|配置key' => 'require',
    ];
}
