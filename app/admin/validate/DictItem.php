<?php

namespace app\admin\validate;

use think\Validate;

class DictItem extends Validate
{
    protected $rule = [
        'name|字典项名称' => 'require',
        'value|字典项值' => 'require',
    ];
}
