<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use app\common\game\exception\GameException;
use app\common\game\platform\WechatMiniProvider;
use app\common\game\service\GameSessionService;
use app\common\game\service\LoginService;
use app\common\game\service\LoginThrottle;
use think\facade\Db;

$prefix = 'test_login_' . bin2hex(random_bytes(6)) . '_';
$app = testApp($prefix);
$passed = 0;
$server = null;
$children = [];
function check(bool $ok, string $message): void
{
    global $passed;
    if (!$ok) throw new RuntimeException($message);
    $passed++;
    echo "PASS $message\n";
}
function rejects(callable $operation, string $code): void
{
    try { $operation(); } catch (GameException $e) { check($e->errorCode === $code, 'reject ' . $code); return; }
    throw new RuntimeException('Expected rejection ' . $code);
}
function request(string $path, string $method = 'GET', ?array $body = null, string $token = ''): array
{
    global $port;
    $curl = curl_init('http://127.0.0.1:' . $port . $path);
    $headers = ['Content-Type: application/json'];
    if ($token) $headers[] = 'Authorization: Bearer ' . $token;
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 5]);
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    return [$status, json_decode((string) $raw, true), (string) $raw];
}

try {
    $sql = preg_replace('/^--.*$/m', '', file_get_contents(dirname(__DIR__, 2) . '/database/migrations/001_game_login.sql'));
    foreach (explode(';', str_replace('{{prefix}}', $prefix, $sql)) as $statement) if (trim($statement) !== '') Db::execute($statement);
    $settings = ['app_id' => 'wx3dd63318911f5012', 'app_secret' => 'test-secret'];
    $upstream = new WechatMiniProvider($settings, fn() => json_encode(['openid' => 'verified-id', 'session_key' => 'private-key', 'errcode' => 0]));
    $identity = $upstream->exchange('code');
    check($identity['openid'] === 'verified-id' && !isset($identity['session_key']), 'verified identity excludes platform session key');
    foreach ([40029 => 'WECHAT_CODE_INVALID', 40125 => 'WECHAT_CONFIG_ERROR', 40226 => 'WECHAT_ACCOUNT_RESTRICTED', 45011 => 'WECHAT_RATE_LIMITED', -1 => 'WECHAT_UNAVAILABLE'] as $error => $code) {
        rejects(fn() => (new WechatMiniProvider($settings, fn() => json_encode(['errcode' => $error, 'errmsg' => 'private-data'])))->exchange('code'), $code);
    }
    foreach (['<html>bad gateway</html>', '{"openid":"forged"}', 'null'] as $body) {
        rejects(fn() => (new WechatMiniProvider($settings, fn() => $body))->exchange('code'), 'WECHAT_UNAVAILABLE');
    }
    rejects(fn() => (new WechatMiniProvider([]))->exchange('code'), 'WECHAT_NOT_CONFIGURED');
    $logins = new LoginService(); $sessions = new GameSessionService(); $provider = testProvider();
    rejects(fn() => $logins->login($provider, '', '127.0.0.1'), 'PARAM_ERROR');
    check($provider->calls === 0, 'invalid input never calls platform');
    $first = $logins->login($provider, 'first-code', '::1');
    $second = $logins->login($provider, 'second-code', '::1');
    check($first['is_new_player'] && !$second['is_new_player'] && $first['player']['id'] === $second['player']['id'], 'repeat login retains stable UID');
    check(Db::name('user')->count() === 1 && Db::name('game_identity')->count() === 1, 'repeat login creates one user and identity');
    check($first['token_info']['access_token'] !== $second['token_info']['access_token'], 'sessions use independent random tokens');
    check(Db::name('game_session')->where('token_hash', hash('sha256', $first['token_info']['access_token']))->count() === 1, 'only SHA-256 token digest is persisted');
    $auth = $sessions->authenticate('Bearer ' . $first['token_info']['access_token']);
    check((string) $auth['user_id'] === $first['player']['id'], 'bearer authenticates verified UID');
    rejects(fn() => $sessions->authenticate('Bearer ' . $first['token_info']['access_token'] . 'bad'), 'SESSION_INVALID');
    rejects(fn() => $sessions->authenticate('Bearer administrator.jwt.token'), 'SESSION_INVALID');
    $sessions->revoke($auth);
    rejects(fn() => $sessions->authenticate('Bearer ' . $first['token_info']['access_token']), 'SESSION_INVALID');
    $sessions->authenticate('Bearer ' . $second['token_info']['access_token']);
    check(true, 'logout does not revoke unrelated sessions');
    Db::name('game_session')->where('token_hash', hash('sha256', $second['token_info']['access_token']))->update(['expires_at_ms' => 1]);
    rejects(fn() => $sessions->authenticate('Bearer ' . $second['token_info']['access_token']), 'SESSION_INVALID');
    $third = $logins->login($provider, 'third-code', '127.0.0.1');
    Db::name('user')->where('id', $first['player']['id'])->update(['status' => 0]);
    rejects(fn() => $sessions->authenticate('Bearer ' . $third['token_info']['access_token']), 'ACCOUNT_DISABLED');
    $before = Db::name('game_session')->count();
    rejects(fn() => $logins->login($provider, 'blocked-code', '127.0.0.1'), 'ACCOUNT_DISABLED');
    check(Db::name('game_session')->count() === $before, 'disabled account cannot create a session');
    Db::name('user')->where('id', $first['player']['id'])->update(['status' => 1, 'delete_time' => time()]);
    rejects(fn() => $sessions->authenticate('Bearer ' . $third['token_info']['access_token']), 'ACCOUNT_DISABLED');
    Db::name('user')->where('id', $first['player']['id'])->update(['delete_time' => null]);
    $otherPlatform = $logins->login(testProvider('test-openid', 'taptap_mini', 'future-tap-app'), 'code', '127.0.0.1');
    $otherApp = $logins->login(testProvider('test-openid', 'wechat_mini', 'wx0000000000000000'), 'code', '127.0.0.1');
    check(count(array_unique([$first['player']['id'], $otherPlatform['player']['id'], $otherApp['player']['id']])) === 3, 'platform and AppID namespaces never merge accounts implicitly');

    // Force the session insert to fail after user creation; the whole login must roll back.
    $usersBefore = Db::name('user')->count();
    Db::execute('RENAME TABLE `' . $prefix . 'game_session` TO `' . $prefix . 'game_session_held`');
    try {
        try { $logins->login(testProvider('rollback-player'), 'code', '127.0.0.1'); throw new RuntimeException('Expected failed insert'); }
        catch (\think\db\exception\PDOException $expected) {}
        check(Db::name('user')->count() === $usersBefore && Db::name('game_identity')->where('openid', 'rollback-player')->count() === 0, 'failed session insert rolls back user and identity');
    } finally { Db::execute('RENAME TABLE `' . $prefix . 'game_session_held` TO `' . $prefix . 'game_session`'); }

    $start = microtime(true) + 1;
    for ($i = 0; $i < 6; $i++) {
        $pipes = [];
        $proc = proc_open([PHP_BINARY, __DIR__ . '/concurrent.php', $prefix, (string) $start], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        $children[] = [$proc, $pipes];
    }
    $ids = [];
    foreach ($children as [$proc, $pipes]) {
        $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($proc);
        $result = json_decode($stdout, true);
        check($exit === 0 && isset($result['uid']) && $result['exchanges'] === 1, 'concurrent login succeeds with one platform exchange');
        $ids[] = $result['uid'];
    }
    $children = [];
    check(count(array_unique($ids)) === 1 && Db::name('user')->count() === $usersBefore + 1, 'concurrent first logins leave no duplicate or orphan users');

    $gameConfig = $app->config->get('game'); $gameConfig['login_rate'] = 2; $app->config->set($gameConfig, 'game');
    $throttle = new LoginThrottle(); $throttle->consume('192.0.2.4'); $throttle->consume('192.0.2.4');
    rejects(fn() => $throttle->consume('192.0.2.4'), 'TOO_MANY_REQUESTS');

    // Exercise the real router and auth middleware against the isolated test tables.
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $address = stream_socket_get_name($socket, false); fclose($socket); $port = (int) substr(strrchr($address, ':'), 1);
    $runtime = dirname(__DIR__) . '/.runtime'; if (!is_dir($runtime)) mkdir($runtime, 0700, true);
    $env = getenv(); $env['GAME_TEST_PREFIX'] = $prefix;
    $pipes = [];
    $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/router.php'],
        [1 => ['file', $runtime . '/http.stdout.log', 'a'], 2 => ['file', $runtime . '/http.stderr.log', 'a']], $pipes, dirname(__DIR__, 2), $env, ['bypass_shell' => true]);
    for ($i = 0; $i < 50; $i++) { usleep(20000); if (request('/v1/health')[0] === 200) break; }
    check(request('/v1/health')[0] === 200, 'HTTP health is reachable');
    check(request('/v1/auth/me')[0] === 401, 'HTTP missing session rejected');
    check(request('/v1/auth/wechat', 'POST', ['code' => 'fake', 'openid' => 'victim'])[0] === 422, 'HTTP client-supplied openid rejected');
    check(request('/v1/auth/wechat', 'POST', [])[0] === 422, 'HTTP empty login rejected');
    check(in_array(request('/v1/auth/wechat')[0], [404, 405], true), 'HTTP login requires POST');
    check(request('/Access/wechat', 'POST', ['code' => 'fake'])[0] === 404, 'conventional controller route cannot bypass explicit routing');
    check(request('/v1/auth/taptap', 'POST', ['code' => 'fake', 'openid' => 'victim'])[0] === 422, 'TapTap login rejects client-supplied identity');
    [$status, $body] = request('/v1/auth/me', 'GET', null, $third['token_info']['access_token']);
    check($status === 200 && $body['player']['id'] === $first['player']['id'] && !isset($body['openid']), 'HTTP bearer returns only public player identity');
    check(request('/v1/auth/logout', 'POST', [], $third['token_info']['access_token'])[0] === 200, 'HTTP logout succeeds');
    check(request('/v1/auth/me', 'GET', null, $third['token_info']['access_token'])[0] === 401, 'HTTP revoked session rejected');
    echo "Game login checks passed: $passed\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    foreach ($children as [$proc, $pipes]) { if (is_resource($proc)) proc_terminate($proc); }
    // Drop only this run's four tables, never the configured live prefix or database.
    foreach (['game_login_throttle', 'game_session', 'game_identity', 'user'] as $table) Db::execute('DROP TABLE IF EXISTS `' . $prefix . $table . '`');
}
