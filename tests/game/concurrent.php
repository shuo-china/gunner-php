<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
testApp($argv[1]);
$start = (float) $argv[2];
while (microtime(true) < $start) usleep(1000);
$provider = testProvider('parallel-new-player', $argv[3] ?? 'wechat_mini', $argv[4] ?? 'wx3dd63318911f5012');
$result = (new \app\common\game\service\LoginService())->login($provider, 'separate-code', '127.0.0.1');
echo json_encode(['uid' => $result['player']['id'], 'exchanges' => $provider->calls]);
