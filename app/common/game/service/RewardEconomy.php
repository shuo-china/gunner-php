<?php
declare(strict_types=1);

namespace app\common\game\service;

use app\common\game\exception\GameException;

/** Generated from Project's EconomyConfig.ts; this file contains no reward amounts. */
final class RewardEconomy
{
    public static function load(): array
    {
        $path = (string) config('game.reward_economy_path', root_path() . 'config/rewards-economy.json');
        try {
            if (!is_file($path)) throw new \RuntimeException('Missing generated economy');
            $config = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            if (($config['schemaVersion'] ?? null) !== 1 || !is_array($config['dailyCheck'] ?? null)
                || !is_array($config['redeemCodes'] ?? null) || !is_array($config['dailyShare'] ?? null)) throw new \RuntimeException('Invalid economy');
            $daily = $config['dailyCheck'];
            if (!is_array($daily['diamondsByDay'] ?? null) || count($daily['diamondsByDay']) !== 7
                || !in_array($daily['dailyLimit'] ?? null, [0, 1], true)) throw new \RuntimeException('Invalid daily economy');
            foreach ($daily['diamondsByDay'] as $diamonds) self::diamonds($diamonds);
            self::diamonds($config['dailyShare']['diamonds'] ?? null);
            if (!in_array($config['dailyShare']['dailyLimit'] ?? null, [0, 1], true)) throw new \RuntimeException('Invalid share economy');
            $codes = [];
            foreach ($config['redeemCodes'] as $definition) {
                $code = $definition['code'] ?? null;
                if (!is_string($code) || $code === '' || strlen($code) > 128 || trim($code) !== $code
                    || preg_match('/[\x00-\x1f\x7f]/', $code) || isset($codes[$code])
                    || !in_array($definition['mode'] ?? null, ['per_player', 'global_once'], true)) throw new \RuntimeException('Invalid redeem economy');
                self::diamonds($definition['diamonds'] ?? null);
                $codes[$code] = true;
            }
            return $config;
        } catch (\Throwable $e) {
            throw new GameException('REWARD_CONFIG_ERROR', '服务器奖励配置暂不可用', 503);
        }
    }

    private static function diamonds($value): void
    {
        if (!is_int($value) || $value < 0 || $value > 9007199254740991) throw new \RuntimeException('Invalid diamonds');
    }
}
