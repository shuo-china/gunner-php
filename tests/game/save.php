<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use app\common\game\service\CloudSaveService;
use app\common\game\service\LoginService;
use app\common\game\exception\GameException;
use think\facade\Db;

$prefix = 'test_login_' . bin2hex(random_bytes(6)) . '_';
$app = testApp($prefix); $passed = 0; $server = null; $children = [];
function check(bool $ok, string $message): void { global $passed; if (!$ok) throw new RuntimeException($message); echo 'PASS ' . $message . "\n"; $passed++; }
function rejects(callable $operation, string $code): void {
    try { $operation(); } catch (GameException $e) { check($e->errorCode === $code, 'reject ' . $code); return; }
    throw new RuntimeException('Expected ' . $code);
}
function request(string $path, string $method = 'GET', ?array $body = null, string $token = ''): array {
    global $port;
    $curl = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token]]);
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($curl); $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
    return [$status, json_decode((string) $raw, true)];
}
try {
    foreach (['001_game_login.sql', '002_game_save.sql'] as $migration) {
        $sql = preg_replace('/^--.*$/m', '', file_get_contents(dirname(__DIR__, 2) . '/database/migrations/' . $migration));
        foreach (explode(';', str_replace('{{prefix}}', $prefix, $sql)) as $statement) if (trim($statement) !== '') Db::execute($statement);
    }
    $login = (new LoginService())->login(testProvider(), 'code', '127.0.0.1'); $uid = (int) $login['player']['id'];
    $other = (new LoginService())->login(testProvider('other'), 'code', '127.0.0.1'); $otherId = (int) $other['player']['id'];
    $service = new CloudSaveService();
    $raw = '{"version":3,"money":{"digits":"12345678901234567890123456789","exponent":8001,"precision":29},"levels":[1,2,3],"weapons":{"testTimeOffsetMs":0},"progress":{"stageIndex":4,"waveIndex":2},"dailyCheck":{"lastClaimDay":"","checkNum":0,"totalCheckNum":0},"dailyShare":{"lastClaimDay":""},"redeemedCodes":[],"adventure":{"future":"保留原始字段"}}';
    $body = ['request_id' => 'request_initial_0001', 'base_revision' => '0', 'save' => $raw];
    check($service->read($uid)['save'] === null, 'new account has no fabricated save');
    $receipt = $service->write($uid, $body);
    check($receipt['revision'] === '1', 'first save creates revision one');
    check($service->read($uid)['save'] === $raw, 'snapshot bytes and huge decimals preserved exactly');
    check($service->read($otherId)['save'] === null, 'another UID cannot read this save');
    check($service->write($uid, $body) === $receipt, 'lost response retries return original receipt');
    check(Db::name('game_save_history')->count() === 1, 'replay creates no extra history');
    rejects(fn() => $service->write($uid, $body + ['user_id' => $otherId]), 'SAVE_INVALID');
    rejects(fn() => $service->write($uid, array_replace($body, ['save' => str_replace('8001', '8002', $raw)])), 'REQUEST_ID_CONFLICT');
    rejects(fn() => $service->write($uid, array_replace($body, ['request_id' => 'request_stale_0001'])), 'SAVE_CONFLICT');
    foreach (['{', '{}', 'null', str_replace('[1,2,3]', '[1,2]', $raw), str_replace('"testTimeOffsetMs":0', '"testTimeOffsetMs":1000', $raw)] as $invalid) {
        rejects(fn() => $service->write($uid, array_replace($body, ['save' => $invalid])), 'SAVE_INVALID');
    }
    rejects(fn() => $service->write($uid, array_replace($body, ['save' => str_repeat(' ', CloudSaveService::MAX_BYTES + 1)])), 'SAVE_TOO_LARGE');
    // The same UID on a separate server-configured namespace is independent.
    $app->config->set(['save_namespace' => 'staging'], 'game');
    check((new CloudSaveService())->read($uid)['revision'] === '0', 'namespace is selected by server config');
    $app->config->set(['save_namespace' => 'production'], 'game');
    Db::name('user')->where('id', $uid)->update(['status' => 0]);
    rejects(fn() => $service->write($uid, $body), 'ACCOUNT_DISABLED');
    Db::name('user')->where('id', $uid)->update(['status' => 1]);
    // Force a failure after updating the current save: the transaction must roll everything back.
    Db::execute('RENAME TABLE `' . $prefix . 'game_save_history` TO `' . $prefix . 'game_save_history_held`');
    try {
        try { $service->write($uid, array_replace($body, ['base_revision' => '1', 'request_id' => 'request_rollback_01'])); throw new RuntimeException('Expected failed history insert'); }
        catch (\think\db\exception\PDOException $expected) {}
        check($service->read($uid)['revision'] === '1' && Db::name('game_save_request')->count() === 1, 'snapshot and receipt roll back if history cannot be written');
    } finally { Db::execute('RENAME TABLE `' . $prefix . 'game_save_history_held` TO `' . $prefix . 'game_save_history`'); }
    // Real independent PHP/database connections race on the missing save row.
    $start = microtime(true) + 0.5;
    for ($i = 0; $i < 2; $i++) {
        $pipes = [];
        $proc = proc_open([PHP_BINARY, __DIR__ . '/save-concurrent.php', $prefix, (string) $otherId,
            json_encode(array_replace($body, ['request_id' => 'request_concurrent_' . $i])), (string) $start],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), null, ['bypass_shell' => true]);
        $children[] = [$proc, $pipes];
    }
    $results = [];
    foreach ($children as [$proc, $pipes]) {
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        check(proc_close($proc) === 0, 'concurrent save process exits cleanly: ' . $err); $results[] = json_decode($out, true);
    }
    $children = [];
    check(count(array_filter($results, fn($r) => $r['ok'])) === 1 && count(array_filter($results, fn($r) => ($r['code'] ?? '') === 'SAVE_CONFLICT')) === 1, 'simultaneous first saves have exactly one winner');
    for ($i = 1; $i < 26; $i++) $service->write($uid, ['request_id' => 'request_history_' . str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'base_revision' => (string) $i, 'save' => $raw]);
    $replay = $service->write($uid, $body);
    check($replay['revision'] === '1' && $replay['current_revision'] === '26', 'old receipt reports newer head without reverting it');
    $history = Db::name('game_save_history')->where('user_id', $uid)->column('revision');
    check(count($history) === 21 && in_array(1, $history) && in_array(26, $history), 'history keeps first import and latest twenty');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error); $address = stream_socket_get_name($socket, false); fclose($socket);
    $port = (int) substr(strrchr($address, ':'), 1); $env = getenv(); $env['GAME_TEST_PREFIX'] = $prefix;
    $runtime = dirname(__DIR__) . '/.runtime'; if (!is_dir($runtime)) mkdir($runtime, 0700, true); $pipes = [];
    $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/router.php'],
        [1 => ['file', $runtime . '/save.stdout.log', 'a'], 2 => ['file', $runtime . '/save.stderr.log', 'a']], $pipes, dirname(__DIR__, 2), $env, ['bypass_shell' => true]);
    for ($i = 0; $i < 50; $i++) { usleep(20000); if (request('/v1/health')[0] === 200) break; }
    $token = $login['token_info']['access_token']; $otherToken = $other['token_info']['access_token'];
    check(request('/v1/save')[0] === 401 && request('/v1/save', 'POST', $body)[0] === 401, 'HTTP save read and write require authentication');
    [$status, $data] = request('/v1/save', 'GET', null, $token);
    check($status === 200 && $data['save'] === $raw && $data['revision'] === '26', 'HTTP reads authenticated player snapshot');
    check(request('/v1/save', 'GET', null, $otherToken)[1]['revision'] === '1', 'HTTP ignores other account state');
    check(request('/v1/save', 'POST', $body, $token)[0] === 200, 'HTTP repeated upload succeeds idempotently');
    check(request('/v1/save', 'POST', array_replace($body, ['request_id' => 'request_http_stale']), $token)[0] === 409, 'HTTP rejects stale revision');
    check(request('/Save/read', 'GET', null, $token)[0] === 404, 'controller paths cannot bypass auth routing');
    echo "Cloud save checks passed: $passed\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    foreach ($children as [$proc, $pipes]) if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
    foreach (['game_save_request', 'game_save_history', 'game_save', 'game_login_throttle', 'game_session', 'game_identity', 'user'] as $table) Db::execute('DROP TABLE IF EXISTS `' . $prefix . $table . '`');
}
