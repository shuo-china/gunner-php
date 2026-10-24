<?php
use think\facade\Route;
use app\middleware\GameAuth;

Route::post('v1/auth/wechat', 'Access/wechat');
Route::get('v1/auth/me', 'Access/me')->middleware(GameAuth::class);
Route::post('v1/auth/logout', 'Access/logout')->middleware(GameAuth::class);
Route::get('v1/save', 'Save/read')->middleware(GameAuth::class);
Route::post('v1/save', 'Save/write')->middleware(GameAuth::class);
Route::get('v1/rewards/state', 'Reward/state')->middleware(GameAuth::class);
Route::post('v1/rewards/daily', 'Reward/daily')->middleware(GameAuth::class);
Route::post('v1/rewards/share', 'Reward/share')->middleware(GameAuth::class);
Route::post('v1/rewards/redeem', 'Reward/redeem')->middleware(GameAuth::class);
Route::post('v1/rewards/offline', 'Reward/offline')->middleware(GameAuth::class);
Route::get('v1/health', function () {
    return json(['service' => 'forest-gunner-game', 'version' => 1], 200, ['Cache-Control' => 'no-store']);
});
