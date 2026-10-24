<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use app\common\game\platform\TapTapMiniProvider;
use app\common\game\exception\GameException;

// Pure protocol tests: no configuration, database or live platform is contacted.
$passed = 0;
function check(bool $ok, string $message): void { global $passed; if (!$ok) throw new RuntimeException($message); echo "PASS $message\n"; $passed++; }
function rejects(callable $operation, string $code, int $status): void {
    try { $operation(); } catch (GameException $e) {
        check($e->errorCode === $code && $e->status === $status, 'reject ' . $code);
        check(!str_contains($e->getMessage(), 'fixture-secret') && !str_contains($e->getMessage(), 'fixture-code'), 'error excludes upstream credentials');
        return;
    }
    throw new RuntimeException('Expected ' . $code);
}
$settings = ['app_id' => 'tapmcg8evaubisjmzc', 'app_secret' => 'fixture-secret'];
$ok = ['openid' => 'tap-test-user', 'session_key' => 'fixture-session-key', 'unionid' => 'tap-union-user'];
$identity = (new TapTapMiniProvider($settings, function ($endpoint, $query) use ($ok) {
    check($endpoint === 'https://cloud-miniapp.tapapis.cn/auth/v1/jscode2session', 'only official domestic HTTPS endpoint is used');
    check($query === ['appid' => 'tapmcg8evaubisjmzc', 'secret' => 'fixture-secret', 'js_code' => 'fixture-code', 'grant_type' => 'authorization_code'], 'exchange uses documented MiniApp fields');
    return json_encode($ok);
}))->exchange('fixture-code');
check($identity === ['provider' => 'taptap_mini', 'app_id' => $settings['app_id'], 'openid' => $ok['openid'], 'unionid' => $ok['unionid']], 'verified identity is scoped and excludes session key');
unset($ok['unionid']);
check((new TapTapMiniProvider($settings, fn() => json_encode($ok)))->exchange('fixture-code')['unionid'] === null, 'unlinked application need not return unionid');
$opaque = ['openid' => 'tap+opaque/id=', 'session_key' => 'fixture-session-key', 'unionid' => 'union+opaque/id=='];
$opaqueIdentity = (new TapTapMiniProvider($settings, fn() => json_encode($opaque)))->exchange('fixture-code');
check($opaqueIdentity['openid'] === $opaque['openid'] && $opaqueIdentity['unionid'] === $opaque['unionid'], 'opaque identity punctuation is preserved exactly');
foreach (['openid', 'unionid'] as $field) {
    $boundary = array_replace($opaque, [$field => str_repeat('x', 128)]);
    check((new TapTapMiniProvider($settings, fn() => json_encode($boundary)))->exchange('fixture-code')[$field] === $boundary[$field], $field . ' accepts the existing 128-byte column limit');
    foreach (['', str_repeat('x', 129), "id\n", 'bad user', "id\t", "id\x00", "id\x7f", '用户'] as $invalid) {
        $badIdentity = array_replace($opaque, [$field => $invalid]);
        rejects(fn() => (new TapTapMiniProvider($settings, fn() => json_encode($badIdentity)))->exchange('fixture-code'), 'TAPTAP_UNAVAILABLE', 502);
    }
}
foreach ([1040029, '1040029', 40029, '40029'] as $error) {
    rejects(fn() => (new TapTapMiniProvider($settings, fn() => json_encode(['errcode' => $error, 'errmsg' => 'fixture-secret fixture-code'])))->exchange('fixture-code'), 'TAPTAP_CODE_INVALID', 401);
}
foreach ([-1, 40013, [], 'unexpected', null] as $error) {
    rejects(fn() => (new TapTapMiniProvider($settings, fn() => json_encode(['errcode' => $error])))->exchange('fixture-code'), 'TAPTAP_UNAVAILABLE', 502);
}
foreach (['<html>error</html>', 'null', '{}', '{"openid":"forged"}', json_encode($ok + ['unionid' => []]),
    json_encode(['openid' => 'bad user', 'session_key' => 'key']), json_encode(['openid' => 'user', 'session_key' => '']), str_repeat('x', 65537)] as $body) {
    rejects(fn() => (new TapTapMiniProvider($settings, fn() => $body))->exchange('fixture-code'), 'TAPTAP_UNAVAILABLE', 502);
}
rejects(fn() => (new TapTapMiniProvider($settings, function () { throw new RuntimeException('fixture-secret fixture-code'); }))->exchange('fixture-code'), 'TAPTAP_UNAVAILABLE', 502);
foreach ([[], ['app_id' => 'wrong-id', 'app_secret' => 'fixture-secret'], ['app_id' => $settings['app_id'], 'app_secret' => 'fill-on-server']] as $bad) {
    rejects(fn() => (new TapTapMiniProvider($bad))->exchange('fixture-code'), 'TAPTAP_NOT_CONFIGURED', 503);
}
echo "TapTap provider checks passed: $passed\n";
