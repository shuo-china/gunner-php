<?php
declare(strict_types=1);

namespace app\common\game\platform;

interface IdentityProvider
{
    /** Return only a server-verified identity; never a platform session key. */
    public function exchange(string $code): array;
}
