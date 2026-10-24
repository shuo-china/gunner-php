<?php

namespace app\admin\model;

use think\model\concern\SoftDelete;

class ExceptionLog extends BaseModel
{
    use SoftDelete;

    protected $name = 'exception';
}
