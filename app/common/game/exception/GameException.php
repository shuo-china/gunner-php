<?php
declare(strict_types=1);

namespace app\common\game\exception;

final class GameException extends \RuntimeException
{
    public function __construct(
        public string $errorCode,
        string $message,
        public int $status = 400,
        public array $headers = []
    ) {
        parent::__construct($message);
    }
}
