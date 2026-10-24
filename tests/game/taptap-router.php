<?php
declare(strict_types=1);
// This dedicated CLI test server is outside public/. No production route enables fixtures.
if (PHP_SAPI !== 'cli-server') exit(1);
require __DIR__ . '/bootstrap.php';
use app\common\game\platform\TapTapMiniProvider;
$app = testApp((string) getenv('GAME_TEST_PREFIX'));
$app->instance(TapTapMiniProvider::class, new TapTapMiniProvider(
    ['app_id' => 'tapmcg8evaubisjmzc', 'app_secret' => 'fixture-secret'],
    function ($endpoint, $query) {
        if ($query['js_code'] === 'invalid-code') return '{"errcode":1040029,"errmsg":"invalid code"}';
        if ($query['js_code'] === 'unavailable-code') return '<html>upstream unavailable</html>';
        return json_encode(['openid' => $query['js_code'] === 'account-b' ? 'tap-http-player-b' : 'tap-http-player-a', 'session_key' => 'fixture-session-key']);
    }
));
$app->http->name('game');
$response = $app->http->run();
$response->send();
$app->http->end($response);
