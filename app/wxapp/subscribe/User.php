<?php
declare(strict_types=1);

namespace app\wxapp\subscribe;

class User
{
    protected $eventPrefix = 'User';

    public function onLoginAfter($user)
    {
        $user->last_login_ip = get_client_ip();
        $user->last_login_time = time();
        $user->save();
    }
}
