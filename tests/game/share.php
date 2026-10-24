<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use app\common\game\service\CloudSaveService;
use app\common\game\service\RewardService;
use app\common\game\service\RewardEconomy;
use app\common\game\service\LoginService;
use app\common\game\exception\GameException;
use think\facade\Db;

$prefix = 'test_login_' . bin2hex(random_bytes(6)) . '_';
$app = testApp($prefix); $passed = 0; $server = null; $children = []; $fixtureConfig = null; $databaseReady = false; $failure = null;
function check(bool $ok, string $message): void { global $passed; if (!$ok) throw new RuntimeException($message); echo 'PASS ' . $message . "\n"; $passed++; }
function rejects(callable $operation, string $code): void {
    try { $operation(); } catch (GameException $e) { check($e->errorCode === $code, 'reject ' . $code); return; }
    throw new RuntimeException('Expected ' . $code);
}
function player(string $name, ?string $save): array {
    global $saves;
    $login = (new LoginService())->login(testProvider('share-' . $name), 'code', '127.0.0.1');
    $uid = (int) $login['player']['id'];
    if ($save !== null) $saves->write($uid, ['request_id' => 'initial_save_0000', 'base_revision' => '0', 'save' => $save]);
    return [$uid, $login['token_info']['access_token']];
}
function race(array $claims): array {
    global $prefix, $fixtureConfig, $children;
    $start = microtime(true) + 0.5;
    foreach ($claims as [$uid, $data]) {
        $pipes = [];
        $proc = proc_open([PHP_BINARY, __DIR__ . '/rewards-concurrent.php', $prefix, (string) $uid, 'share',
            json_encode($data), (string) $start, $fixtureConfig], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            dirname(__DIR__, 2), null, ['bypass_shell' => true]);
        $children[] = [$proc, $pipes];
    }
    $results = [];
    foreach ($children as [$proc, $pipes]) {
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        check(proc_close($proc) === 0, 'concurrent share process exits cleanly: ' . $err);
        $results[] = json_decode($out, true, 64, JSON_THROW_ON_ERROR);
    }
    $children = []; return $results;
}
function request(string $path, string $method = 'GET', ?array $body = null, string $token = ''): array {
    global $port;
    $curl = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token]]);
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($curl); $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
    return [$status, json_decode((string) $raw, true)];
}
try {
    foreach (['001_game_login.sql', '002_game_save.sql', '003_game_rewards.sql', '004_game_share.sql'] as $migration) {
        $sql = preg_replace('/^--.*$/m', '', file_get_contents(dirname(__DIR__, 2) . '/database/migrations/' . $migration));
        foreach (explode(';', str_replace('{{prefix}}', $prefix, $sql)) as $statement) if (trim($statement) !== '') {
            Db::execute($statement); $databaseReady = true;
        }
    }
    $pipes = [];
    $proc = proc_open(['node', __DIR__ . '/reward-fixture.cjs'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), null, ['bypass_shell' => true]);
    $raw = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($proc) === 0 && $raw !== '', 'real simulator fixture is available: ' . $error);
    $economy = RewardEconomy::load();
    $fixtureConfig = tempnam(sys_get_temp_dir(), 'share-test-'); file_put_contents($fixtureConfig, json_encode($economy));
    $settings = $app->config->get('game'); $settings['reward_economy_path'] = $fixtureConfig; $app->config->set($settings, 'game');
    $saves = new CloudSaveService(); $rewards = new RewardService();
    [$uid, $token] = player('main', $raw); [$missing] = player('missing', null);
    $share = ['request_id' => 'share_request_0001', 'base_revision' => '1'];
    rejects(fn() => $rewards->claim($missing, 'share', $share), 'SAVE_REQUIRED');
    foreach (['diamonds' => 999999, 'day' => '2099-01-01', 'now_ms' => 9999999999999, 'platform' => 'wechat'] as $key => $value) {
        rejects(fn() => $rewards->claim($uid, 'share', $share + [$key => $value]), 'REWARD_INVALID');
    }
    $state = $rewards->state($uid);
    check($state['daily_share']['canClaim'] && !$state['daily_share']['blocked'] && $state['daily_share']['lastClaimDay'] === '', 'current initial share ledger starts eligible');
    $newState = $rewards->state($missing);
    check($newState['daily_share']['canClaim'] && !$newState['daily_share']['blocked'], 'new account without a snapshot has an initial share preview');
    check($state['daily_share']['diamonds'] === $economy['dailyShare']['diamonds'] && $economy['dailyShare']['diamonds'] === 80, 'share preview uses the configured 80 diamonds');
    $award = $rewards->claim($uid, 'share', $share); $saved = json_decode($award['save'], true);
    check($award['revision'] === '2' && $award['reward'] === ['kind' => 'share', 'diamonds' => $economy['dailyShare']['diamonds']], 'share commits configured amount and one revision');
    check($saved['weapons']['diamonds'] === $economy['dailyShare']['diamonds'] && $saved['dailyShare']['lastClaimDay'] === $award['daily_share']['today'] && !$award['daily_share']['canClaim'], 'wallet and share ledger commit together');
    check(str_contains($award['save'], '"future":{}'), 'share keeps unrelated future objects');
    check($rewards->claim($uid, 'share', $share)['save'] === $award['save'], 'lost share response retries without paying twice');
    rejects(fn() => $rewards->claim($uid, 'share', ['request_id' => 'share_request_0002', 'base_revision' => '2']), 'SHARE_NOT_CLAIMABLE');
    rejects(fn() => $rewards->claim($uid, 'offline', $share), 'REQUEST_ID_CONFLICT');
    $daily = $rewards->claim($uid, 'daily', ['request_id' => 'daily_request_0001', 'base_revision' => '2', 'day_index' => 0]);
    check(!$daily['daily_share']['canClaim'] && json_decode($daily['save'], true)['dailyShare'] === $saved['dailyShare'], 'check-in has an independent allowance and retains share state');
    $missingLedger = json_decode($daily['save']); unset($missingLedger->dailyShare);
    rejects(fn() => $saves->write($uid, ['request_id' => 'missing_share_001', 'base_revision' => '3',
        'save' => json_encode($missingLedger)]), 'REWARD_STATE_CONFLICT');
    $preserved = $saves->read($uid);
    check($preserved['save'] === $daily['save'] && $preserved['revision'] === '3', 'rejected missing mirror preserves the exact current snapshot');
    check(!$rewards->state($uid)['daily_share']['canClaim'] && Db::name('game_share_claim')->where('user_id', $uid)->count() === 1, 'rejected upload preserves the independent server claim ledger');
    rejects(fn() => $rewards->claim($uid, 'share', ['request_id' => 'repeat_share_0001', 'base_revision' => '3']), 'SHARE_NOT_CLAIMABLE');
    foreach ([null, ['lastClaimDay' => '2099-01-01']] as $replacement) {
        $changed = json_decode($preserved['save']); $changed->dailyShare = $replacement;
        rejects(fn() => $saves->write($uid, ['request_id' => 'changed_share_001', 'base_revision' => '3', 'save' => json_encode($changed)]), 'REWARD_STATE_CONFLICT');
    }
    $offline = $rewards->claim($uid, 'offline', ['request_id' => 'share_offline_001', 'base_revision' => '3']);
    check(json_decode($offline['save'], true)['dailyShare'] === $saved['dailyShare'] && !$offline['daily_share']['canClaim'], 'offline settlement preserves the current share ledger and allowance');
    $retry = $rewards->claim($uid, 'share', $share);
    check($retry['revision'] === $offline['revision'] && $retry['awarded_revision'] === '2' && $retry['save'] === $offline['save'], 'old share receipt returns current head without reverting later progress');
    $namespaceSettings = $settings; $namespaceSettings['save_namespace'] = 'share-test'; $app->config->set($namespaceSettings, 'game');
    $isolatedSaves = new CloudSaveService(); $isolatedRewards = new RewardService();
    $isolatedSaves->write($uid, ['request_id' => 'isolated_save_001', 'base_revision' => '0', 'save' => $raw]);
    check($isolatedRewards->claim($uid, 'share', $share)['namespace'] === 'share-test', 'share allowance and request IDs are isolated by save namespace');
    $app->config->set($settings, 'game');
    [$sameUid] = player('same-request', $raw);
    $results = race([[$sameUid, $share], [$sameUid, $share]]);
    check($results[0]['ok'] && $results[1]['ok'] && $results[0]['revision'] === $results[1]['revision'], 'concurrent retries receive one shared result');
    check(json_decode($saves->read($sameUid)['save'], true)['weapons']['diamonds'] === $economy['dailyShare']['diamonds'], 'each account receives its own share allowance');
    check(Db::name('game_share_claim')->where('user_id', $sameUid)->count() === 1, 'concurrent retries persist one daily share row');
    [$raceUid] = player('different-request', $raw);
    $results = race([[$raceUid, $share], [$raceUid, ['request_id' => 'share_request_race', 'base_revision' => '1']]]);
    check(count(array_filter($results, fn($r) => $r['ok'])) === 1 && count(array_filter($results, fn($r) => ($r['code'] ?? '') === 'SAVE_CONFLICT')) === 1, 'different requests race safely at the same cloud revision');
    rejects(fn() => $rewards->claim($raceUid, 'share', ['request_id' => 'share_after_race1', 'base_revision' => '2']), 'SHARE_NOT_CLAIMABLE');
    $yesterday = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->modify('-1 day')->format('Y-m-d');
    $previous = json_decode($raw); $previous->dailyShare = (object) ['lastClaimDay' => $yesterday];
    [$nextUid] = player('next-day', json_encode($previous));
    check($rewards->claim($nextUid, 'share', $share)['daily_share']['lastClaimDay'] !== $yesterday, 'previous-day share becomes available on the server day');
    $stateMethod = new ReflectionMethod(RewardService::class, 'shareState'); $stateMethod->setAccessible(true);
    $midnight = (new DateTimeImmutable('2026-10-25T00:00:00+08:00'))->getTimestamp() * 1000;
    check(!$stateMethod->invoke($rewards, ['lastClaimDay' => '2026-10-24'], $midnight - 1)['canClaim']
        && $stateMethod->invoke($rewards, ['lastClaimDay' => '2026-10-24'], $midnight)['canClaim'], 'share resets exactly at Shanghai midnight');
    foreach ([null, ['lastClaimDay' => '2026-02-30'], ['lastClaimDay' => 0]] as $index => $badLedger) {
        $damaged = json_decode($raw); $damaged->dailyShare = $badLedger;
        [$badUid] = player('damaged-' . $index, json_encode($damaged));
        check($rewards->state($badUid)['daily_share']['blocked'], 'damaged share ledger is blocked');
        rejects(fn() => $rewards->claim($badUid, 'share', $share), 'REWARD_STATE_INVALID');
        $badOffline = $rewards->claim($badUid, 'offline', ['request_id' => 'damaged_offline_1', 'base_revision' => '1']);
        check(json_decode($badOffline['save'], true)['dailyShare'] === $badLedger && $badOffline['daily_share']['blocked'], 'offline settlement preserves damaged explicit share state');
    }
    $full = json_decode($raw); $full->weapons->diamonds = 9007199254740991;
    [$fullUid] = player('wallet-limit', json_encode($full));
    rejects(fn() => $rewards->claim($fullUid, 'share', $share), 'REWARD_WALLET_LIMIT');
    check($saves->read($fullUid)['revision'] === '1' && Db::name('game_share_claim')->where('user_id', $fullUid)->count() === 0, 'wallet limit does not consume share allowance');
    [$rollbackUid] = player('rollback', $raw);
    Db::execute('RENAME TABLE `' . $prefix . 'game_save_history` TO `' . $prefix . 'game_save_history_held`');
    try {
        try { $rewards->claim($rollbackUid, 'share', $share); throw new RuntimeException('Expected history failure'); }
        catch (\think\db\exception\PDOException $expected) {}
        check($saves->read($rollbackUid)['revision'] === '1' && Db::name('game_share_claim')->where('user_id', $rollbackUid)->count() === 0
            && Db::name('game_reward_request')->where('user_id', $rollbackUid)->count() === 0, 'history failure rolls back share wallet, allowance and receipt');
    } finally { Db::execute('RENAME TABLE `' . $prefix . 'game_save_history_held` TO `' . $prefix . 'game_save_history`'); }
    check($rewards->claim($rollbackUid, 'share', $share)['revision'] === '2', 'rolled-back request remains claimable');
    $changedEconomy = $economy; $changedEconomy['dailyShare']['diamonds'] = 81;
    file_put_contents($fixtureConfig, json_encode($changedEconomy)); $changedRewards = new RewardService();
    [$tunedUid] = player('configured-amount', $raw);
    check($changedRewards->state($tunedUid)['daily_share']['diamonds'] === 81 && $changedRewards->claim($tunedUid, 'share', $share)['reward']['diamonds'] === 81, 'configuration changes preview and settlement together');
    $changedEconomy['dailyShare']['dailyLimit'] = 0; file_put_contents($fixtureConfig, json_encode($changedEconomy)); $paused = new RewardService();
    [$pausedUid] = player('paused', $raw);
    check(!$paused->state($pausedUid)['daily_share']['canClaim'], 'zero daily limit pauses eligibility');
    rejects(fn() => $paused->claim($pausedUid, 'share', $share), 'SHARE_NOT_CLAIMABLE');
    check($paused->claim($uid, 'share', $share)['reward']['diamonds'] === $economy['dailyShare']['diamonds'], 'tuning and pausing do not alter an existing receipt');
    file_put_contents($fixtureConfig, json_encode($economy));
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error); $address = stream_socket_get_name($socket, false); fclose($socket);
    $port = (int) substr(strrchr($address, ':'), 1); $env = getenv(); $env['GAME_TEST_PREFIX'] = $prefix;
    $runtime = dirname(__DIR__) . '/.runtime'; if (!is_dir($runtime)) mkdir($runtime, 0700, true); $pipes = [];
    $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/router.php'],
        [1 => ['file', $runtime . '/share.stdout.log', 'a'], 2 => ['file', $runtime . '/share.stderr.log', 'a']], $pipes, dirname(__DIR__, 2), $env, ['bypass_shell' => true]);
    for ($i = 0; $i < 50; $i++) { usleep(20000); if (request('/v1/health')[0] === 200) break; }
    check(request('/v1/rewards/share', 'POST', $share)[0] === 401, 'HTTP share requires authentication');
    check(request('/v1/rewards/share', 'POST', $share + ['diamonds' => 800], $token)[0] === 422, 'HTTP share rejects client reward values');
    [$status, $body] = request('/v1/rewards/share', 'POST', $share, $token);
    check($status === 200 && $body['revision'] === $offline['revision'] && $body['awarded_revision'] === '2' && !$body['daily_share']['canClaim'], 'HTTP share retry returns original reward and current state');
    [$status, $body] = request('/v1/rewards/state', 'GET', null, $token);
    check($status === 200 && $body['daily_share']['diamonds'] === $economy['dailyShare']['diamonds'] && !$body['daily_share']['canClaim'], 'HTTP reward state includes share eligibility');
    check(request('/Reward/share', 'POST', $share, $token)[0] === 404, 'controller path cannot bypass share routing');
    echo "Backend share checks passed: $passed\n";
} catch (\Throwable $error) { $failure = $error; }
finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    foreach ($children as [$proc, $pipes]) if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
    if ($fixtureConfig && is_file($fixtureConfig)) unlink($fixtureConfig);
    if ($databaseReady) foreach (['game_share_claim', 'game_daily_claim', 'game_redeem_claim', 'game_redeem_code', 'game_reward_request', 'game_save_request', 'game_save_history', 'game_save', 'game_login_throttle', 'game_session', 'game_identity', 'user'] as $table) Db::execute('DROP TABLE IF EXISTS `' . $prefix . $table . '`');
}
if ($failure) { fwrite(STDERR, get_class($failure) . ': ' . $failure->getMessage() . "\n"); exit(1); }
