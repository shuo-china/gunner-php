<?php

namespace app\admin\validate;

use think\Validate;

class Menu extends Validate
{
    protected $rule = [
        'parent_id' => 'require',
        'title' => 'require',
        'path' => 'require|unique:menu',
    ];
}
