<?php

namespace app\admin\validate;

use think\Validate;

class ConfigGroup extends Validate
{
    protected $rule = [
        'name|配置分组名称' => 'require',
        'key|配置分组Key' => 'require|unique:config_group',
    ];
}
