<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
$app = testApp($argv[1]);
$settings = $app->config->get('game'); $settings['reward_economy_path'] = $argv[6]; $app->config->set($settings, 'game');
while (microtime(true) < (float) $argv[5]) usleep(1000);
try {
    $result = (new \app\common\game\service\RewardService())->claim((int) $argv[2], $argv[3], json_decode($argv[4], true, 8, JSON_THROW_ON_ERROR));
    echo json_encode(['ok' => true, 'revision' => $result['revision'], 'reward' => $result['reward']]);
} catch (\app\common\game\exception\GameException $e) { echo json_encode(['ok' => false, 'code' => $e->errorCode]); }
