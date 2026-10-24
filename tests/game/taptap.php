<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use app\common\game\exception\GameException;
use app\common\game\service\CloudSaveService;
use app\common\game\service\GameSessionService;
use app\common\game\service\LoginService;
use app\common\game\service\RewardService;
use app\common\game\service\RewardEconomy;
use think\facade\Db;

// Unlike old suites this integration suite requires an explicit isolated loopback DB.
if (!getenv('GAME_TEST_DB_PORT') || !getenv('GAME_TEST_DB_NAME')) throw new RuntimeException('An explicit isolated test database is required');
$prefix = 'test_login_' . bin2hex(random_bytes(6)) . '_';
$app = testApp($prefix); $passed = 0; $server = null; $children = []; $databaseReady = false;
function check(bool $ok, string $message): void { global $passed; if (!$ok) throw new RuntimeException($message); echo "PASS $message\n"; $passed++; }
function rejects(callable $operation, string $code): void {
    try { $operation(); } catch (GameException $e) { check($e->errorCode === $code, 'reject ' . $code); return; }
    throw new RuntimeException('Expected ' . $code);
}
function request(string $path, string $method = 'GET', ?array $body = null, string $token = ''): array {
    global $port;
    $curl = curl_init('http://127.0.0.1:' . $port . $path); $received = [];
    $headers = ['Content-Type: application/json', 'Origin: https://h5-test.example'];
    if ($token !== '') $headers[] = 'Authorization: Bearer ' . $token;
    if ($method === 'OPTIONS') $headers = [...$headers, 'Access-Control-Request-Method: POST', 'Access-Control-Request-Headers: content-type,authorization'];
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$received) {
            $parts = explode(':', $line, 2); if (count($parts) === 2) $received[strtolower(trim($parts[0]))] = trim($parts[1]); return strlen($line);
        }]);
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($curl); $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
    return [$status, json_decode((string) $raw, true), $received, (string) $raw];
}
try {
    foreach (['001_game_login.sql', '002_game_save.sql', '003_game_rewards.sql', '004_game_share.sql'] as $migration) {
        $sql = preg_replace('/^--.*$/m', '', file_get_contents(dirname(__DIR__, 2) . '/database/migrations/' . $migration));
        foreach (explode(';', str_replace('{{prefix}}', $prefix, $sql)) as $statement) if (trim($statement) !== '') { Db::execute($statement); $databaseReady = true; }
    }
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error); $address = stream_socket_get_name($socket, false); fclose($socket);
    $port = (int) substr(strrchr($address, ':'), 1); $env = getenv(); $env['GAME_TEST_PREFIX'] = $prefix;
    $runtime = dirname(__DIR__) . '/.runtime'; if (!is_dir($runtime)) mkdir($runtime, 0700, true); $pipes = [];
    $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/taptap-router.php'],
        [1 => ['file', $runtime . '/taptap.stdout.log', 'a'], 2 => ['file', $runtime . '/taptap.stderr.log', 'a']], $pipes, dirname(__DIR__, 2), $env, ['bypass_shell' => true]);
    for ($i = 0; $i < 50; $i++) { usleep(20000); if (request('/v1/health')[0] === 200) break; }
    check(request('/v1/health')[0] === 200, 'isolated HTTP test server is ready');
    [$status, , $headers] = request('/v1/auth/taptap', 'OPTIONS');
    check($status === 204 && ($headers['access-control-allow-origin'] ?? '') === 'https://h5-test.example', 'login preflight succeeds with existing CORS origin policy');
    check(str_contains(strtolower($headers['access-control-allow-headers'] ?? ''), 'authorization')
        && str_contains(strtolower($headers['access-control-allow-headers'] ?? ''), 'content-type'), 'preflight permits JSON and Bearer headers');
    check(request('/v1/save', 'OPTIONS')[0] === 204 && request('/v1/rewards/daily', 'OPTIONS')[0] === 204, 'authenticated APIs preflight without a session');
    foreach ([[], ['code' => 'fake', 'openid' => 'victim'], ['code' => 'fake', 'appid' => 'other'], ['code' => 'fake', 'secret' => 'fake'], ['code' => ''], ['code' => str_repeat('x', 257)], ['code' => "bad code"], ['code' => 1]] as $bad) {
        [$status, $body, $headers] = request('/v1/auth/taptap', 'POST', $bad);
        check($status === 422 && $body['code'] === 'PARAM_ERROR' && isset($headers['access-control-allow-origin']), 'login rejects malformed or extra input with readable CORS error');
    }
    check(in_array(request('/v1/auth/taptap')[0], [404, 405], true) && request('/Access/taptap', 'POST', ['code' => 'account-a'])[0] === 404, 'only explicit POST TapTap login route is reachable');
    foreach (['invalid-code' => [401, 'TAPTAP_CODE_INVALID'], 'unavailable-code' => [502, 'TAPTAP_UNAVAILABLE']] as $code => [$expectedStatus, $expectedCode]) {
        [$status, $body, $headers] = request('/v1/auth/taptap', 'POST', ['code' => $code]);
        check($status === $expectedStatus && $body['code'] === $expectedCode && isset($headers['access-control-allow-origin']), 'platform errors preserve status and CORS: ' . $expectedCode);
    }
    [$status, $first, $headers, $rawResponse] = request('/v1/auth/taptap', 'POST', ['code' => 'account-a']);
    check($status === 200 && $first['is_new_player'] && $first['player']['provider'] === 'taptap_mini' && $first['player']['app_id'] === 'tapmcg8evaubisjmzc', 'first HTTP login creates scoped TapTap identity');
    check(isset($headers['access-control-allow-origin']) && ($headers['cache-control'] ?? '') === 'no-store'
        && !str_contains($rawResponse, 'openid') && !str_contains($rawResponse, 'session_key') && !str_contains($rawResponse, 'fixture-secret'), 'success is browser-readable and excludes private identity material');
    [, $again] = request('/v1/auth/taptap', 'POST', ['code' => 'account-a']);
    check(!$again['is_new_player'] && $again['player']['id'] === $first['player']['id'] && $again['token_info']['access_token'] !== $first['token_info']['access_token'], 'repeat HTTP login retains UID with a fresh session');
    $uid = (int) $first['player']['id']; $token = $first['token_info']['access_token'];
    [, $accountB] = request('/v1/auth/taptap', 'POST', ['code' => 'account-b']);
    check($accountB['player']['id'] !== $first['player']['id'], 'switching TapTap account selects a separate player');
    $wechat = (new LoginService())->login(testProvider('tap-http-player-a'), 'code', '127.0.0.1');
    $otherApp = (new LoginService())->login(testProvider('tap-http-player-a', 'taptap_mini', 'tapotherapplication'), 'code', '127.0.0.1');
    check(count(array_unique([$first['player']['id'], $wechat['player']['id'], $otherApp['player']['id']])) === 3, 'same openid across platform or MiniApp never merges accounts');
    Db::name('game_session')->where('token_hash', hash('sha256', $token))->update(['expires_at_ms' => 1]);
    check(request('/v1/auth/me', 'GET', null, $token)[0] === 401, 'expired TapTap session is rejected');
    [, $renewed] = request('/v1/auth/taptap', 'POST', ['code' => 'account-a']); $token = $renewed['token_info']['access_token'];
    check($renewed['player']['id'] === (string) $uid && request('/v1/auth/me', 'GET', null, $token)[0] === 200, 'renewal returns to the same player');
    Db::name('user')->where('id', $uid)->update(['status' => 0]);
    $sessionCount = Db::name('game_session')->where('user_id', $uid)->count();
    check(request('/v1/auth/me', 'GET', null, $token)[0] === 403 && request('/v1/auth/taptap', 'POST', ['code' => 'account-a'])[0] === 403, 'disabled TapTap account cannot authenticate or log in');
    check(Db::name('game_session')->where('user_id', $uid)->count() === $sessionCount, 'disabled login issues no session');
    Db::name('user')->where('id', $uid)->update(['status' => 1]);

    $pipes = []; $fixture = proc_open(['node', __DIR__ . '/reward-fixture.cjs'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), null, ['bypass_shell' => true]);
    $raw = stream_get_contents($pipes[1]); $fixtureError = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($fixture) === 0 && $raw !== '', 'current simulator snapshot is available');
    $saves = new CloudSaveService(); $rewards = new RewardService();
    $saved = $saves->write($uid, ['request_id' => 'tap_initial_save01', 'base_revision' => '0', 'save' => $raw]);
    check($saves->read((int) $accountB['player']['id'])['save'] === null && $saves->read((int) $wechat['player']['id'])['save'] === null, 'TapTap progress is isolated from other accounts and WeChat');
    check(!$rewards->state($uid)['daily_share']['canClaim'] && !$rewards->state((int) $accountB['player']['id'])['daily_share']['canClaim'], 'TapTap share allowance is unavailable with or without a save');
    $claim = ['request_id' => 'tap_share_request1', 'base_revision' => '1'];
    rejects(fn() => $rewards->claim($uid, 'share', $claim), 'SHARE_UNSUPPORTED');
    [$status, $body, $headers] = request('/v1/rewards/share', 'POST', $claim, $token);
    check($status === 403 && $body['code'] === 'SHARE_UNSUPPORTED' && isset($headers['access-control-allow-origin']), 'HTTP TapTap share claim is denied from verified identity');
    check($saves->read($uid)['revision'] === '1' && Db::name('game_share_claim')->where('user_id', $uid)->count() === 0
        && Db::name('game_reward_request')->where('user_id', $uid)->count() === 0, 'share rejection preserves wallet, revision and ledgers');
    $daily = ['request_id' => 'tap_daily_request1', 'base_revision' => '1', 'day_index' => 0];
    [$status, $award] = request('/v1/rewards/daily', 'POST', $daily, $token);
    check($status === 200 && $award['revision'] === '2' && !$award['daily_share']['canClaim'], 'TapTap check-in works while sharing remains unavailable');
    [, $retry] = request('/v1/rewards/daily', 'POST', $daily, $token);
    check($retry['save'] === $award['save'] && $retry['revision'] === '2', 'lost TapTap reward response retries without double payment');
    check(json_decode($award['save'], true)['dailyShare'] === json_decode($raw, true)['dailyShare'], 'TapTap rewards preserve existing share save schema');
    rejects(fn() => $saves->write($uid, ['request_id' => 'tap_stale_device01', 'base_revision' => '1', 'save' => $raw]), 'SAVE_CONFLICT');
    $upload = ['request_id' => 'tap_reconnect_0001', 'base_revision' => '2', 'save' => $award['save']];
    check($saves->write($uid, $upload)['revision'] === '3' && $saves->write($uid, $upload)['revision'] === '3', 'reconnected upload retries are idempotent');
    $redeem = ['request_id' => 'tap_redeem_request1', 'base_revision' => '3', 'code' => RewardEconomy::load()['redeemCodes'][0]['code']];
    [$status, $redeemed] = request('/v1/rewards/redeem', 'POST', $redeem, $token);
    check($status === 200 && $redeemed['reward']['kind'] === 'redeem' && $redeemed['revision'] === '4', 'TapTap redeem code uses existing server rewards');
    [, $redeemRetry] = request('/v1/rewards/redeem', 'POST', $redeem, $token);
    check($redeemRetry['save'] === $redeemed['save'], 'TapTap redeem retry never pays twice');
    $offline = ['request_id' => 'tap_offline_request1', 'base_revision' => '4'];
    [$status, $settled] = request('/v1/rewards/offline', 'POST', $offline, $token);
    check($status === 200 && $settled['reward']['kind'] === 'offline' && $settled['revision'] === '5', 'TapTap offline settlement uses existing server simulation');
    [, $offlineRetry] = request('/v1/rewards/offline', 'POST', $offline, $token);
    check($offlineRetry['save'] === $settled['save'], 'TapTap offline retry preserves the committed settlement');
    check(request('/v1/save', 'GET', null, $accountB['token_info']['access_token'])[1]['save'] === null, 'account switch cannot read the previous account save');
    check(request('/v1/auth/logout', 'POST', [], $token)[0] === 200 && request('/v1/auth/me', 'GET', null, $token)[0] === 401, 'TapTap logout revokes the active session');
    for ($i = 0; $i < 35; $i++) {
        [$limitStatus, $limitBody, $limitHeaders] = request('/v1/auth/taptap', 'POST', ['code' => '']);
        if ($limitStatus === 429) break;
    }
    check($limitStatus === 429 && $limitBody['code'] === 'TOO_MANY_REQUESTS' && isset($limitHeaders['retry-after'])
        && isset($limitHeaders['access-control-allow-origin']), 'TapTap login shares the existing limiter and browser-readable throttle response');

    $before = Db::name('user')->count(); $start = microtime(true) + 0.5;
    for ($i = 0; $i < 6; $i++) {
        $pipes = []; $process = proc_open([PHP_BINARY, __DIR__ . '/concurrent.php', $prefix, (string) $start, 'taptap_mini', 'tapmcg8evaubisjmzc'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), null, ['bypass_shell' => true]); $children[] = [$process, $pipes];
    }
    $ids = [];
    foreach ($children as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process); $result = json_decode($output, true);
        check($exit === 0 && isset($result['uid']) && $result['exchanges'] === 1, 'concurrent TapTap login exchanges a code only once'); $ids[] = $result['uid'];
    }
    $children = [];
    check(count(array_unique($ids)) === 1 && Db::name('user')->count() === $before + 1, 'concurrent first TapTap logins create one player without orphans');
    echo "TapTap integration checks passed: $passed\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    foreach ($children as [$process, $pipes]) if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if ($databaseReady) foreach (['game_share_claim', 'game_daily_claim', 'game_redeem_claim', 'game_redeem_code', 'game_reward_request', 'game_save_request', 'game_save_history', 'game_save', 'game_login_throttle', 'game_session', 'game_identity', 'user'] as $table) Db::execute('DROP TABLE IF EXISTS `' . $prefix . $table . '`');
}
