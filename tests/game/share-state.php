<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use app\common\game\service\CloudSaveService;
use app\common\game\service\RewardService;
use app\common\game\service\RewardEconomy;
use app\common\game\exception\GameException;

// Pure date, request and current snapshot checks; this suite never connects to a database.
$app = testApp('test_login_' . bin2hex(random_bytes(6)) . '_'); $passed = 0;
function check(bool $ok, string $message): void { global $passed; if (!$ok) throw new RuntimeException($message); echo 'PASS ' . $message . "\n"; $passed++; }
function method(string $class, string $name): ReflectionMethod { $method = new ReflectionMethod($class, $name); $method->setAccessible(true); return $method; }
try {
    $rewards = new RewardService(); $saves = new CloudSaveService(); $economy = RewardEconomy::load();
    $state = method(RewardService::class, 'shareState'); $reset = method(RewardService::class, 'nextReset');
    $midnight = (new DateTimeImmutable('2026-10-25T00:00:00+08:00'))->getTimestamp() * 1000;
    $before = $state->invoke($rewards, ['lastClaimDay' => '2026-10-24'], $midnight - 1);
    $after = $state->invoke($rewards, ['lastClaimDay' => '2026-10-24'], $midnight);
    check(!$before['canClaim'] && $before['today'] === '2026-10-24', 'share remains used before Shanghai midnight');
    check($after['canClaim'] && $after['today'] === '2026-10-25', 'share reopens at Shanghai midnight');
    check($reset->invoke($rewards, $midnight - 1) === $midnight && $reset->invoke($rewards, $midnight) === $midnight + 86400000, 'reset deadline follows the same server day');
    $empty = $state->invoke($rewards, ['lastClaimDay' => ''], $midnight);
    check($empty['canClaim'] && !$empty['blocked'] && $empty['diamonds'] === $economy['dailyShare']['diamonds'], 'current initial ledger uses the configured reward');
    check(!$state->invoke($rewards, ['lastClaimDay' => '2099-01-01'], $midnight)['canClaim'], 'future saved day cannot unlock a second reward');
    foreach ([null, [], 'bad', ['lastClaimDay' => 0], ['lastClaimDay' => '2026-02-30']] as $raw) {
        $bad = $state->invoke($rewards, $raw, $midnight);
        check($bad['blocked'] && !$bad['canClaim'], 'malformed optional share ledger fails closed');
    }
    $request = method(RewardService::class, 'validateRequest');
    $claim = ['request_id' => 'share_request_0001', 'base_revision' => '1'];
    $request->invoke($rewards, 'share', $claim);
    foreach (['diamonds' => 800, 'day' => '2026-10-25', 'platform' => 'wechat'] as $key => $value) {
        try { $request->invoke($rewards, 'share', $claim + [$key => $value]); throw new RuntimeException('Expected extra field rejection'); }
        catch (GameException $error) { check($error->errorCode === 'REWARD_INVALID', 'share request rejects client-supplied ' . $key); }
    }
    $compare = method(CloudSaveService::class, 'assertRewardLedgersUnchanged');
    $snapshot = method(RewardService::class, 'snapshotState');
    $newAccount = $snapshot->invoke($rewards, null, $midnight, '');
    check($newAccount['daily_check']['canClaim'] && $newAccount['daily_share']['canClaim'], 'account without a snapshot receives initial reward previews');
    $current = ['dailyCheck' => ['lastClaimDay' => '', 'checkNum' => 0, 'totalCheckNum' => 0],
        'dailyShare' => ['lastClaimDay' => '2026-10-24'], 'redeemedCodes' => []];
    $old = json_encode($current);
    $compare->invoke($saves, $old, $old);
    foreach (['dailyCheck' => 'daily_check', 'dailyShare' => 'daily_share'] as $key => $responseKey) {
        $missing = $current; unset($missing[$key]);
        $missingState = $snapshot->invoke($rewards, json_encode($missing), $midnight, '');
        check($missingState[$responseKey]['blocked'] && !$missingState[$responseKey]['canClaim'], 'existing snapshot missing ' . $key . ' remains blocked');
    }
    foreach (['dailyCheck', 'dailyShare', 'redeemedCodes'] as $key) {
        $missing = $current; unset($missing[$key]);
        foreach ([[$old, json_encode($missing)], [json_encode($missing), $old], [json_encode($missing), json_encode($missing)]] as [$before, $after]) {
            try { $compare->invoke($saves, $before, $after); throw new RuntimeException('Expected missing ledger conflict'); }
            catch (GameException $error) { check($error->errorCode === 'REWARD_STATE_CONFLICT', 'missing ' . $key . ' cannot be imported or erased by an update'); }
        }
    }
    check(!$state->invoke($rewards, ['lastClaimDay' => ''], $midnight - 1, '2026-10-24')['canClaim'], 'independent claim ledger prevents reward replay');
    check($state->invoke($rewards, ['lastClaimDay' => '2026-10-25'], $midnight, '2026-10-24')['lastClaimDay'] === '2026-10-25', 'latest valid snapshot or database claim day governs eligibility');
    check($state->invoke($rewards, null, $midnight, '2026-10-24')['blocked'], 'damaged explicit mirror remains blocked even with a valid database claim');
    foreach (['null', '{"lastClaimDay":""}', '{"lastClaimDay":"2099-01-01"}'] as $replacement) {
        $changed = $current; $changed['dailyShare'] = json_decode($replacement, true);
        try { $compare->invoke($saves, $old, json_encode($changed)); throw new RuntimeException('Expected ledger conflict'); }
        catch (GameException $error) { check($error->errorCode === 'REWARD_STATE_CONFLICT', 'explicit share ledger replacement is rejected'); }
    }
    echo "Backend share state checks passed: $passed\n";
} catch (\Throwable $error) { fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n"); exit(1); }
