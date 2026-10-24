<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
testApp($argv[1]);
while (microtime(true) < (float) $argv[4]) usleep(1000);
try {
    $result = (new \app\common\game\service\CloudSaveService())->write((int) $argv[2], json_decode($argv[3], true, 8, JSON_THROW_ON_ERROR));
    echo json_encode(['ok' => true, 'revision' => $result['revision']]);
} catch (\app\common\game\exception\GameException $e) { echo json_encode(['ok' => false, 'code' => $e->errorCode]); }
