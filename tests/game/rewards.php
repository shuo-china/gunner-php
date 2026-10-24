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
$app = testApp($prefix); $passed = 0; $server = null; $children = []; $fixtureConfig = null;
function check(bool $ok, string $message): void { global $passed; if (!$ok) throw new RuntimeException($message); echo 'PASS ' . $message . "\n"; $passed++; }
function rejects(callable $operation, string $code): void {
    try { $operation(); } catch (GameException $e) { check($e->errorCode === $code, 'reject ' . $code); return; }
    throw new RuntimeException('Expected ' . $code);
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
function race(array $claims): array {
    global $prefix, $fixtureConfig, $children;
    $start = microtime(true) + 0.5;
    foreach ($claims as [$uid, $kind, $data]) {
        $pipes = [];
        $proc = proc_open([PHP_BINARY, __DIR__ . '/rewards-concurrent.php', $prefix, (string) $uid, $kind,
            json_encode($data), (string) $start, $fixtureConfig], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            dirname(__DIR__, 2), null, ['bypass_shell' => true]);
        $children[] = [$proc, $pipes];
    }
    $results = [];
    foreach ($children as [$proc, $pipes]) {
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        check(proc_close($proc) === 0, 'concurrent reward process exits cleanly: ' . $err); $results[] = json_decode($out, true, 64, JSON_THROW_ON_ERROR);
    }
    $children = []; return $results;
}
try {
    foreach (['001_game_login.sql', '002_game_save.sql', '003_game_rewards.sql', '004_game_share.sql'] as $migration) {
        $sql = preg_replace('/^--.*$/m', '', file_get_contents(dirname(__DIR__, 2) . '/database/migrations/' . $migration));
        foreach (explode(';', str_replace('{{prefix}}', $prefix, $sql)) as $statement) if (trim($statement) !== '') Db::execute($statement);
    }
    $pipes = [];
    $proc = proc_open(['node', __DIR__ . '/reward-fixture.cjs'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), null, ['bypass_shell' => true]);
    $raw = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($proc) === 0 && $raw !== '', 'real simulator fixture is available: ' . $error);
    $economy = RewardEconomy::load();
    $publicCode = $economy['redeemCodes'][0];
    $economy['redeemCodes'][] = ['code' => 'TEST_GLOBAL_ONCE', 'diamonds' => 17, 'mode' => 'global_once'];
    $economy['redeemCodes'][] = ['code' => 'TEST_GLOBAL_ROLLBACK', 'diamonds' => 19, 'mode' => 'global_once'];
    $fixtureConfig = tempnam(sys_get_temp_dir(), 'reward-test-'); file_put_contents($fixtureConfig, json_encode($economy));
    $settings = $app->config->get('game'); $settings['reward_economy_path'] = $fixtureConfig; $app->config->set($settings, 'game');
    $saves = new CloudSaveService(); $rewards = new RewardService(); $users = [];
    for ($i = 0; $i < 7; $i++) {
        $login = (new LoginService())->login(testProvider('reward-player-' . $i), 'code', '127.0.0.1');
        $uid = (int) $login['player']['id']; $users[] = [$uid, $login['token_info']['access_token']];
        if ($i < 6) $saves->write($uid, ['request_id' => 'initial_save_0000', 'base_revision' => '0', 'save' => $raw]);
    }
    [$uid, $token] = $users[0]; [$otherId] = $users[1];
    $daily = ['request_id' => 'daily_request_0001', 'base_revision' => '1', 'day_index' => 0];
    rejects(fn() => $rewards->claim($users[6][0], 'daily', $daily), 'SAVE_REQUIRED');
    $newState = $rewards->state($users[6][0]);
    check($newState['daily_check']['canClaim'] && $newState['daily_share']['canClaim'], 'new account without a snapshot has initial reward previews');
    foreach (['diamonds' => 999999, 'now_ms' => 9999999999999, 'user_id' => $otherId, 'save' => $raw] as $key => $value) {
        rejects(fn() => $rewards->claim($uid, 'daily', $daily + [$key => $value]), 'REWARD_INVALID');
    }
    $state = $rewards->state($uid);
    check($state['player_id'] === (string) $uid && $state['namespace'] === 'production' && $state['daily_check']['claimableIndex'] === 0, 'state is scoped to authenticated owner');
    $reset = (new DateTimeImmutable('@' . intdiv($state['next_daily_reset_ms'], 1000)))->setTimezone(new DateTimeZone('Asia/Shanghai'));
    check($reset->format('H:i:s') === '00:00:00' && $state['next_daily_reset_ms'] > $state['server_time_ms'], 'daily reset is next Shanghai midnight');
    rejects(fn() => $rewards->claim($uid, 'daily', array_replace($daily, ['day_index' => 6])), 'DAILY_NOT_CLAIMABLE');
    $award = $rewards->claim($uid, 'daily', $daily); $saved = json_decode($award['save'], true);
    check($award['revision'] === '2' && $award['awarded_revision'] === '2' && $award['reward']['diamonds'] === $economy['dailyCheck']['diamondsByDay'][0], 'daily amount comes from generated config and commits one revision');
    check($saved['weapons']['diamonds'] === $award['reward']['diamonds'] && $saved['dailyCheck']['checkNum'] === 1 && !$award['daily_check']['canClaim'], 'wallet and daily ledger change together');
    check(str_contains($award['save'], '"future":{}'), 'unrelated empty objects survive reward serialization');
    $retry = $rewards->claim($uid, 'daily', $daily);
    check($retry['save'] === $award['save'] && $retry['reward'] === $award['reward'], 'lost daily response replays without paying again');
    rejects(fn() => $rewards->claim($uid, 'daily', array_replace($daily, ['day_index' => 1])), 'REQUEST_ID_CONFLICT');
    rejects(fn() => $rewards->claim($uid, 'daily', array_replace($daily, ['request_id' => 'daily_request_again', 'base_revision' => '2', 'day_index' => 1])), 'DAILY_NOT_CLAIMABLE');
    rejects(fn() => $saves->write($uid, ['request_id' => 'old_save_replay_01', 'base_revision' => '2', 'save' => $raw]), 'REWARD_STATE_CONFLICT');
    $typedLedger = json_decode($award['save'], true); $typedLedger['dailyCheck']['checkNum'] = '1';
    rejects(fn() => $saves->write($uid, ['request_id' => 'typed_ledger_bad01', 'base_revision' => '2', 'save' => json_encode($typedLedger)]), 'REWARD_STATE_CONFLICT');
    rejects(fn() => $saves->write($uid, ['request_id' => 'old_save_replay_02', 'base_revision' => '1', 'save' => $raw]), 'SAVE_CONFLICT');
    $redeem = ['request_id' => 'redeem_request_001', 'base_revision' => '2', 'code' => $publicCode['code']];
    $codeAward = $rewards->claim($uid, 'redeem', $redeem);
    check($codeAward['reward']['diamonds'] === $publicCode['diamonds'], 'public code grants configured amount');
    $otherAward = $rewards->claim($otherId, 'redeem', array_replace($redeem, ['base_revision' => '1']));
    check($otherAward['reward']['diamonds'] === $publicCode['diamonds'], 'per_player code grants once to each account');
    rejects(fn() => $rewards->claim($uid, 'redeem', array_replace($redeem, ['request_id' => 'redeem_request_002', 'base_revision' => '3'])), 'REDEEM_ALREADY_USED');
    rejects(fn() => $rewards->claim($uid, 'redeem', array_replace($redeem, ['request_id' => 'redeem_request_bad', 'base_revision' => '3', 'code' => 'UNKNOWN'])), 'REDEEM_INVALID');
    $old = $rewards->claim($uid, 'daily', $daily);
    check($old['awarded_revision'] === '2' && $old['revision'] === '3' && $old['save'] === $codeAward['save'], 'old receipt returns current head rather than reverting later rewards');
    $race = [];
    foreach (array_slice($users, 2, 3) as [$id]) $race[] = [$id, 'redeem', ['request_id' => 'global_request_' . str_pad((string) $id, 5, '0', STR_PAD_LEFT), 'base_revision' => '1', 'code' => 'TEST_GLOBAL_ONCE']];
    $results = race($race);
    check(count(array_filter($results, fn($r) => $r['ok'])) === 1 && count(array_filter($results, fn($r) => ($r['code'] ?? '') === 'REDEEM_ALREADY_USED')) === 2, 'global_once has exactly one winner across three real connections');
    check(Db::name('game_redeem_claim')->where('code', 'TEST_GLOBAL_ONCE')->count() === 1, 'global unique claim ledger contains one row');
    $raceUid = $users[5][0];
    $results = race([[$raceUid, 'daily', $daily], [$raceUid, 'daily', $daily]]);
    check($results[0]['ok'] && $results[1]['ok'] && $results[0]['revision'] === $results[1]['revision'], 'simultaneous retries of same daily request return the same award');
    check(Db::name('game_daily_claim')->where('user_id', $raceUid)->count() === 1, 'simultaneous daily retry persists one claim');
    $before = $saves->read($uid); $rollback = ['request_id' => 'rollback_reward_01', 'base_revision' => $before['revision'], 'code' => 'TEST_GLOBAL_ROLLBACK'];
    Db::execute('RENAME TABLE `' . $prefix . 'game_save_history` TO `' . $prefix . 'game_save_history_held`');
    try {
        try { $rewards->claim($uid, 'redeem', $rollback); throw new RuntimeException('Expected history failure'); }
        catch (\think\db\exception\PDOException $expected) {}
        check($saves->read($uid)['save'] === $before['save'] && Db::name('game_redeem_claim')->where('code', 'TEST_GLOBAL_ROLLBACK')->count() === 0, 'history failure rolls back wallet, code consumption and receipt');
    } finally { Db::execute('RENAME TABLE `' . $prefix . 'game_save_history_held` TO `' . $prefix . 'game_save_history`'); }
    $rewards->claim($uid, 'redeem', $rollback);
    check(Db::name('game_redeem_claim')->where('code', 'TEST_GLOBAL_ROLLBACK')->count() === 1, 'rolled back global code remains claimable');
    $head = $saves->read($uid); $serverNow = (int) floor(microtime(true) * 1000);
    Db::name('game_save')->where('user_id', $uid)->update(['saved_at_ms' => $serverNow - 3600000]);
    $resume = ['request_id' => 'startup_resume_001', 'base_revision' => $head['revision'], 'save' => $head['save'], 'resume' => true];
    $resumed = $saves->write($uid, $resume);
    check($saves->read($uid)['saved_at_ms'] === $serverNow - 3600000, 'startup catch-up preserves the existing server offline anchor');
    check($saves->write($uid, array_diff_key($resume, ['resume' => true])) === $resumed, 'resume does not change pending save request identity');
    $resumedAgain = $saves->write($uid, array_replace($resume, ['request_id' => 'startup_resume_002', 'base_revision' => $resumed['revision']]));
    check($saves->read($uid)['saved_at_ms'] === $serverNow - 3600000, 'repeated resume can preserve but cannot rewind the server anchor');
    rejects(fn() => $saves->write($uid, array_replace($resume, ['resume' => false])), 'SAVE_INVALID');
    $offline = ['request_id' => 'offline_reward_001', 'base_revision' => $resumedAgain['revision']];
    $settled = $rewards->claim($uid, 'offline', $offline);
    check($settled['reward']['result']['elapsedSeconds'] >= 3600 && $settled['reward']['result']['elapsedSeconds'] < 3602, 'offline elapsed uses persisted server checkpoint despite forged client time');
    check(json_decode($settled['save'], true)['offline']['lastSavedAtMs'] === $settled['saved_at_ms'], 'offline snapshot checkpoint and DB timestamp match');
    $replayed = $rewards->claim($uid, 'offline', $offline);
    check($replayed['save'] === $settled['save'] && $replayed['reward'] === $settled['reward'], 'offline retry cannot settle the same period again');
    $fresh = $rewards->claim($uid, 'offline', ['request_id' => 'offline_reward_002', 'base_revision' => $settled['revision']]);
    check($fresh['reward']['result'] === null, 'fresh offline claim receives no subthreshold reward');
    $originalNode = $settings['node_binary'] ?? 'node';
    $settings['node_binary'] = '__missing_reward_node_binary__'; $app->config->set($settings, 'game');
    rejects(fn() => $rewards->claim($uid, 'offline', ['request_id' => 'offline_worker_bad', 'base_revision' => $fresh['revision']]), 'REWARD_CALCULATION_FAILED');
    check($saves->read($uid)['revision'] === $fresh['revision'], 'worker failure leaves cloud revision unchanged');
    $settings['node_binary'] = $originalNode; $app->config->set($settings, 'game');
    // A valid current snapshot retains completed seven-day progress; the next server day cycles to day one.
    $cycleUid = $users[6][0]; $cycle = json_decode($raw, true);
    $cycle['dailyCheck'] = ['lastClaimDay' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->modify('-1 day')->format('Y-m-d'), 'checkNum' => 7, 'totalCheckNum' => 7];
    $cycleStart = (int) floor(microtime(true) * 1000);
    $saves->write($cycleUid, ['request_id' => 'cycle_import_0001', 'base_revision' => '0', 'save' => json_encode($cycle), 'resume' => true]);
    check($saves->read($cycleUid)['saved_at_ms'] >= $cycleStart, 'first resume import starts a fresh server anchor');
    check($rewards->state($cycleUid)['daily_check']['claimableIndex'] === 0, 'seven-day cycle advances using server date');
    $cycleAward = $rewards->claim($cycleUid, 'daily', $daily);
    check($cycleAward['daily_check']['totalCheckNum'] === 8 && $cycleAward['reward']['dayIndex'] === 0, 'cycle keeps lifetime check count and original day-one amount');
    $cycleOffline = $rewards->claim($cycleUid, 'offline', ['request_id' => 'cycle_offline_0001', 'base_revision' => $cycleAward['revision']]);
    check($cycleOffline['reward']['result'] === null, 'first imported client checkpoint never receives retroactive offline credit');
    $unicode = json_decode($otherAward['save']); $unicode->future = str_repeat('中', 400000);
    Db::name('game_save')->where('user_id', $otherId)->update(['saved_at_ms' => 1]);
    $unicodeStart = (int) floor(microtime(true) * 1000);
    $unicodeSave = $saves->write($otherId, ['request_id' => 'unicode_save_00001', 'base_revision' => $otherAward['revision'],
        'save' => json_encode($unicode, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    check($saves->read($otherId)['saved_at_ms'] >= $unicodeStart, 'ordinary foreground upload advances the server activity checkpoint');
    $unicodeAward = $rewards->claim($otherId, 'offline', ['request_id' => 'unicode_offline_01', 'base_revision' => $unicodeSave['revision']]);
    check(json_decode($unicodeAward['save'])->future === $unicode->future, 'valid UTF-8 snapshot is not expanded beyond worker input limit');
    $badLogin = (new LoginService())->login(testProvider('damaged-reward-ledger'), 'code', '127.0.0.1');
    $badUid = (int) $badLogin['player']['id']; $damaged = json_decode($raw); $damaged->dailyCheck = null; $damaged->redeemedCodes = null;
    $saves->write($badUid, ['request_id' => 'damaged_import_001', 'base_revision' => '0', 'save' => json_encode($damaged)]);
    check($rewards->state($badUid)['daily_check']['blocked'], 'explicit null ledger stays blocked rather than resetting');
    rejects(fn() => $rewards->claim($badUid, 'daily', $daily), 'REWARD_STATE_INVALID');
    rejects(fn() => $rewards->claim($badUid, 'redeem', array_replace($redeem, ['base_revision' => '1'])), 'REWARD_STATE_INVALID');
    $missingLogin = (new LoginService())->login(testProvider('missing-reward-ledgers'), 'code', '127.0.0.1');
    $missingUid = (int) $missingLogin['player']['id']; $missing = json_decode($raw);
    unset($missing->dailyCheck, $missing->dailyShare, $missing->redeemedCodes);
    $saves->write($missingUid, ['request_id' => 'missing_save_0001', 'base_revision' => '0', 'save' => json_encode($missing)]);
    $missingState = $rewards->state($missingUid);
    check($missingState['daily_check']['blocked'] && $missingState['daily_share']['blocked'], 'existing snapshots missing reward ledgers remain blocked');
    rejects(fn() => $rewards->claim($missingUid, 'daily', $daily), 'REWARD_STATE_INVALID');
    rejects(fn() => $rewards->claim($missingUid, 'share', ['request_id' => 'missing_share_001', 'base_revision' => '1']), 'REWARD_STATE_INVALID');
    rejects(fn() => $rewards->claim($missingUid, 'redeem', array_replace($redeem, ['base_revision' => '1'])), 'REWARD_STATE_INVALID');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error); $address = stream_socket_get_name($socket, false); fclose($socket);
    $port = (int) substr(strrchr($address, ':'), 1); $env = getenv(); $env['GAME_TEST_PREFIX'] = $prefix;
    $runtime = dirname(__DIR__) . '/.runtime'; if (!is_dir($runtime)) mkdir($runtime, 0700, true); $pipes = [];
    $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/router.php'],
        [1 => ['file', $runtime . '/rewards.stdout.log', 'a'], 2 => ['file', $runtime . '/rewards.stderr.log', 'a']], $pipes, dirname(__DIR__, 2), $env, ['bypass_shell' => true]);
    for ($i = 0; $i < 50; $i++) { usleep(20000); if (request('/v1/health')[0] === 200) break; }
    foreach (['state' => 'GET', 'daily' => 'POST', 'redeem' => 'POST', 'offline' => 'POST'] as $route => $method) check(request('/v1/rewards/' . $route, $method, $method === 'POST' ? [] : null)[0] === 401, 'HTTP ' . $route . ' requires authentication');
    [$status, $body] = request('/v1/rewards/state', 'GET', null, $token);
    check($status === 200 && $body['player_id'] === (string) $uid && !$body['daily_check']['canClaim'], 'HTTP state includes authenticated server daily status');
    check(request('/v1/rewards/daily', 'POST', $daily + ['diamonds' => 1000000], $token)[0] === 422, 'HTTP rejects client-provided rewards');
    [$status, $body] = request('/v1/rewards/daily', 'POST', $daily, $token);
    check($status === 200 && $body['revision'] === $fresh['revision'] && $body['awarded_revision'] === '2', 'HTTP retry recovers original award and newest cloud head');
    check(request('/Reward/state', 'GET', null, $token)[0] === 404, 'controller path cannot bypass reward authentication');
    Db::name('game_session')->where('token_hash', hash('sha256', $users[1][1]))->update(['expires_at_ms' => 1]);
    check(request('/v1/rewards/redeem', 'POST', $redeem, $users[1][1])[0] === 401, 'expired session cannot claim rewards');
    echo "Backend reward checks passed: $passed\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    foreach ($children as [$proc, $pipes]) if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
    if ($fixtureConfig && is_file($fixtureConfig)) unlink($fixtureConfig);
    foreach (['game_share_claim', 'game_daily_claim', 'game_redeem_claim', 'game_redeem_code', 'game_reward_request', 'game_save_request', 'game_save_history', 'game_save', 'game_login_throttle', 'game_session', 'game_identity', 'user'] as $table) Db::execute('DROP TABLE IF EXISTS `' . $prefix . $table . '`');
}
