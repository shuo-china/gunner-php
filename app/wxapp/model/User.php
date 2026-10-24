<?php

namespace app\wxapp\model;

use think\model\concern\SoftDelete;

class User extends BaseModel
{
    use SoftDelete;
}