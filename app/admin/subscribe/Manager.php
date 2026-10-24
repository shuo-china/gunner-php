<?php
declare(strict_types=1);

namespace app\admin\subscribe;

class Manager
{
    protected $eventPrefix = 'Manager';

    public function onLoginAfter($manager)
    {
        $manager->last_login_ip = get_client_ip();
        $manager->last_login_time = time();
        $manager->save();
    }
}
