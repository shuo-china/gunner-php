<?php

declare(strict_types=1);

namespace app\middleware\annotation;

use Attribute;

#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_METHOD)]
class WxappPermission
{
    public $roles;

    public function __construct($roles)
    {
        $this->roles = $roles;
    }
}
