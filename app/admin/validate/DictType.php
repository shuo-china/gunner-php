<?php

namespace app\admin\validate;

use think\Validate;

class DictType extends Validate
{
    protected $rule = [
        'name|字典类型名称' => 'require',
        'key|字典类型Key' => 'require|unique:dict_type',
    ];
}
