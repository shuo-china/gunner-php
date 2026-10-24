<?php
declare(strict_types=1);
// Explicit CLI test-server entry; public/index.php has no fake platform switch.
require __DIR__ . '/bootstrap.php';
$app = testApp((string) getenv('GAME_TEST_PREFIX'));
$app->http->name('game');
$http = $app->http;
$response = $http->run();
$response->send();
$http->end($response);
