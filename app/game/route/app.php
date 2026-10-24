<?php
use think\facade\Route;
use app\middleware\GameAuth;

Route::post('v1/auth/wechat', 'Access/wechat');
Route::post('v1/auth/taptap', 'Access/taptap');
// Browser JSON and Bearer requests require a successful preflight. The existing
// global AllowCrossDomain middleware supplies the same headers as actual responses.
Route::options('v1/<path>', fn() => response('', 204, ['Cache-Control' => 'no-store']))->pattern(['path' => '.+']);
Route::get('v1/auth/me', 'Access/me')->middleware(GameAuth::class);
Route::post('v1/auth/logout', 'Access/logout')->middleware(GameAuth::class);
Route::get('v1/save', 'Save/read')->middleware(GameAuth::class);
Route::get('v1/startup', 'Reward/startup')->middleware(GameAuth::class);
Route::post('v1/save', 'Save/write')->middleware(GameAuth::class);
Route::get('v1/rewards/state', 'Reward/state')->middleware(GameAuth::class);
Route::post('v1/rewards/daily', 'Reward/daily')->middleware(GameAuth::class);
Route::post('v1/rewards/share', 'Reward/share')->middleware(GameAuth::class);
Route::post('v1/rewards/redeem', 'Reward/redeem')->middleware(GameAuth::class);
Route::post('v1/rewards/offline', 'Reward/offline')->middleware(GameAuth::class);
Route::get('v1/health', function () {
    return json(['service' => 'forest-gunner-game', 'version' => 1], 200, ['Cache-Control' => 'no-store']);
});
