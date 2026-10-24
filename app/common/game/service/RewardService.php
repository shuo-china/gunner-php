<?php
declare(strict_types=1);

namespace app\common\game\service;

use app\common\game\exception\GameException;
use think\facade\Db;

/** Claims, wallet changes, cloud CAS and retry receipts commit in one InnoDB transaction. */
final class RewardService
{
    private const EMPTY_DAILY = ['lastClaimDay' => '', 'checkNum' => 0, 'totalCheckNum' => 0];
    private const EMPTY_SHARE = ['lastClaimDay' => ''];
    private CloudSaveService $saves;
    private array $economy;
    private \DateTimeZone $timezone;

    public function __construct()
    {
        $this->saves = new CloudSaveService();
        $this->economy = RewardEconomy::load();
        $this->timezone = new \DateTimeZone((string) config('game.reward_timezone', 'Asia/Shanghai'));
    }

    public function state(int $userId): array
    {
        $head = $this->saves->read($userId);
        return ['player_id' => (string) $userId, 'namespace' => $this->saves->namespace(),
            'revision' => $head['revision'], 'server_time_ms' => $head['server_time_ms'],
            'next_daily_reset_ms' => $this->nextReset($head['server_time_ms'])]
            + $this->snapshotState($head['save'], $head['server_time_ms'], $this->latestShareDay($userId));
    }

    public function claim(int $userId, string $kind, array $data): array
    {
        $this->validateRequest($kind, $data);
        $hash = hash('sha256', json_encode([$kind, $data['base_revision'], $data['day_index'] ?? null, $data['code'] ?? null], JSON_THROW_ON_ERROR));
        return Db::transaction(function () use ($userId, $kind, $data, $hash) {
            // Same lock order as cloud uploads: user, cloud save, then optional shared code.
            $user = Db::name('user')->where('id', $userId)->lock(true)->find();
            if (!$user || (int) $user['status'] !== 1 || $user['delete_time'] !== null) throw new GameException('ACCOUNT_DISABLED', '账号不可用', 403);
            $current = $this->saves->query('game_save', $userId)->lock(true)->find();
            $receipt = $this->saves->query('game_reward_request', $userId)->where('request_id', $data['request_id'])->find();
            $now = GameSessionService::nowMs();
            if ($receipt) {
                if (!hash_equals($receipt['payload_hash'], $hash)) throw new GameException('REQUEST_ID_CONFLICT', '请求编号已被用于另一项奖励', 409);
                if (!$current) throw new GameException('REWARD_STATE_INVALID', '奖励存档缺失，请联系客服', 409);
                return $this->response($userId, $current, $receipt, $now);
            }
            if (!$current) throw new GameException('SAVE_REQUIRED', '请先同步游戏存档', 409);
            if ((string) $current['revision'] !== $data['base_revision']) throw new GameException('SAVE_CONFLICT', '进度已更新，请重新同步存档', 409);
            // Keep stdClass objects so unrelated empty objects retain their JSON representation.
            $save = json_decode($current['snapshot'], false, 64, JSON_THROW_ON_ERROR);
            if (!is_object($save)) throw new GameException('SAVE_INVALID', '存档结构无效', 422);
            if ($kind === 'daily') $reward = $this->daily($userId, $save, $data, $now);
            elseif ($kind === 'share') $reward = $this->share($userId, $save, $data, $now);
            elseif ($kind === 'redeem') $reward = $this->redeem($userId, $save, $data, $now);
            else {
                $calculated = (new OfflineRewardCalculator())->calculate($save, (int) $current['saved_at_ms'], $now);
                $save = $calculated['save']; $reward = $calculated['reward'];
            }
            $raw = json_encode($save, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $head = $this->saves->commitLocked($userId, $current, $raw, $now);
            $receipt = ['user_id' => $userId, 'namespace' => $this->saves->namespace(), 'request_id' => $data['request_id'],
                'payload_hash' => $hash, 'kind' => $kind, 'revision' => $head['revision'],
                'reward_json' => json_encode($reward, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at_ms' => $now];
            Db::name('game_reward_request')->insert($receipt);
            return $this->response($userId, $head, $receipt, $now);
        });
    }

    private function daily(int $userId, object $save, array $data, int $now): array
    {
        $daily = $save->dailyCheck ?? null;
        $state = $this->dailyState(is_object($daily) ? (array) $daily : $daily, $now);
        if ($state['blocked']) throw new GameException('REWARD_STATE_INVALID', '签到记录无效，请联系客服', 409);
        if (!$state['canClaim'] || $state['claimableIndex'] !== $data['day_index']
            || $this->saves->query('game_daily_claim', $userId)->where('day', $state['today'])->find()) {
            throw new GameException('DAILY_NOT_CLAIMABLE', '今日已签到或签到天数已变化', 409);
        }
        $diamonds = $this->economy['dailyCheck']['diamondsByDay'][$data['day_index']];
        $this->addDiamonds($save, $diamonds);
        $save->dailyCheck = (object) ['lastClaimDay' => $state['today'], 'checkNum' => $data['day_index'] + 1, 'totalCheckNum' => $state['totalCheckNum'] + 1];
        Db::name('game_daily_claim')->insert(['user_id' => $userId, 'namespace' => $this->saves->namespace(),
            'day' => $state['today'], 'request_id' => $data['request_id'], 'created_at_ms' => $now]);
        return ['kind' => 'daily', 'diamonds' => $diamonds, 'dayIndex' => $data['day_index']];
    }

    private function share(int $userId, object $save, array $data, int $now): array
    {
        $share = $save->dailyShare ?? null;
        $state = $this->shareState(is_object($share) ? (array) $share : $share, $now, $this->latestShareDay($userId));
        if ($state['blocked']) throw new GameException('REWARD_STATE_INVALID', '分享记录无效，请联系客服', 409);
        if (!$state['canClaim'] || $this->saves->query('game_share_claim', $userId)->where('day', $state['today'])->find()) {
            throw new GameException('SHARE_NOT_CLAIMABLE', '今日分享奖励已领取', 409);
        }
        $diamonds = $this->economy['dailyShare']['diamonds'];
        $this->addDiamonds($save, $diamonds);
        $save->dailyShare = (object) ['lastClaimDay' => $state['today']];
        Db::name('game_share_claim')->insert(['user_id' => $userId, 'namespace' => $this->saves->namespace(),
            'day' => $state['today'], 'request_id' => $data['request_id'], 'created_at_ms' => $now]);
        return ['kind' => 'share', 'diamonds' => $diamonds];
    }

    private function redeem(int $userId, object $save, array $data, int $now): array
    {
        $definition = null;
        foreach ($this->economy['redeemCodes'] as $candidate) if ($candidate['code'] === $data['code']) { $definition = $candidate; break; }
        if ($definition === null) throw new GameException('REDEEM_INVALID', '兑换码无效或已停用', 422);
        $claimed = $save->redeemedCodes ?? null;
        if (!is_array($claimed) || count(array_filter($claimed, 'is_string')) !== count($claimed)) throw new GameException('REWARD_STATE_INVALID', '兑换记录无效，请联系客服', 409);
        if (in_array($data['code'], $claimed, true) || $this->saves->query('game_redeem_claim', $userId)->where('code', $data['code'])->find()) {
            throw new GameException('REDEEM_ALREADY_USED', '该兑换码已使用', 409);
        }
        $namespace = $this->saves->namespace();
        // Lazy materialization avoids a separate mutable source of economic tuning.
        Db::name('game_redeem_code')->duplicate(['code'])->insert(['namespace' => $namespace, 'code' => $definition['code'],
            'mode' => $definition['mode'], 'diamonds' => $definition['diamonds']]);
        $query = fn() => Db::name('game_redeem_code')->where('namespace', $namespace)->where('code', $definition['code']);
        $code = $query()->lock(true)->find();
        // Changing the meaning of an already-issued code must use a new code string.
        if ($code['mode'] !== $definition['mode'] || (int) $code['diamonds'] !== $definition['diamonds']) {
            throw new GameException('REWARD_CONFIG_ERROR', '兑换码配置已变更，请使用新的兑换码', 503);
        }
        if ($definition['mode'] === 'global_once' && $code['claimed_user_id'] !== null) throw new GameException('REDEEM_ALREADY_USED', '该兑换码已被使用', 409);
        $this->addDiamonds($save, $definition['diamonds']);
        $save->redeemedCodes = [...$claimed, $definition['code']];
        Db::name('game_redeem_claim')->insert(['user_id' => $userId, 'namespace' => $namespace, 'code' => $definition['code'],
            'global_code' => $definition['mode'] === 'global_once' ? $definition['code'] : null,
            'request_id' => $data['request_id'], 'created_at_ms' => $now]);
        if ($definition['mode'] === 'global_once') $query()->update(['claimed_user_id' => $userId, 'claimed_at_ms' => $now]);
        return ['kind' => 'redeem', 'diamonds' => $definition['diamonds'], 'code' => $definition['code']];
    }

    private function addDiamonds(object $save, int $diamonds): void
    {
        $wallet = $save->weapons->diamonds ?? null;
        if (!is_int($wallet) || $wallet < 0 || $wallet > 9007199254740991 - $diamonds) throw new GameException('REWARD_WALLET_LIMIT', '钻石余额无效或已达上限', 422);
        $save->weapons->diamonds = $wallet + $diamonds;
    }

    private function dailyState($raw, int $now): array
    {
        $empty = self::EMPTY_DAILY;
        $data = $raw;
        $valid = is_array($data) && is_int($data['checkNum'] ?? null) && $data['checkNum'] >= 0 && $data['checkNum'] <= 7
            && is_int($data['totalCheckNum'] ?? null) && $data['totalCheckNum'] >= $data['checkNum'] && $data['totalCheckNum'] <= 9007199254740991
            && is_string($data['lastClaimDay'] ?? null);
        if ($valid) {
            $valid = $data['totalCheckNum'] === 0
                ? $data['checkNum'] === 0 && $data['lastClaimDay'] === ''
                : $this->validDay($data['lastClaimDay']) && $data['checkNum'] % 7 === $data['totalCheckNum'] % 7;
        }
        if (!$valid) $data = $empty;
        $today = $this->date($now)->format('Y-m-d');
        $nextDay = $today > $data['lastClaimDay'];
        if ($data['checkNum'] === 7 && $nextDay) $data['checkNum'] = 0;
        $canClaim = $valid && $nextDay && $this->economy['dailyCheck']['dailyLimit'] > 0 && $data['totalCheckNum'] < 9007199254740991;
        $days = [];
        foreach ($this->economy['dailyCheck']['diamondsByDay'] as $day => $diamonds) {
            $days[] = ['dayIndex' => $day, 'diamonds' => $diamonds,
                'status' => $day < $data['checkNum'] ? 'geted' : ($day === $data['checkNum'] && $canClaim ? 'getable' : 'normal')];
        }
        return $data + ['today' => $today, 'canClaim' => $canClaim, 'claimableIndex' => $canClaim ? $data['checkNum'] : null,
            'blocked' => !$valid, 'days' => $days];
    }

    private function latestShareDay(int $userId): string
    {
        return (string) ($this->saves->query('game_share_claim', $userId)->order('day', 'desc')->value('day') ?? '');
    }

    private function shareState($raw, int $now, string $claimedDay = ''): array
    {
        $valid = is_array($raw) && is_string($raw['lastClaimDay'] ?? null)
            && ($raw['lastClaimDay'] === '' || $this->validDay($raw['lastClaimDay']));
        $lastClaimDay = max($valid ? $raw['lastClaimDay'] : '', $claimedDay);
        $today = $this->date($now)->format('Y-m-d');
        return ['lastClaimDay' => $lastClaimDay, 'today' => $today,
            'canClaim' => $valid && $today > $lastClaimDay && $this->economy['dailyShare']['dailyLimit'] > 0,
            'blocked' => !$valid, 'diamonds' => $this->economy['dailyShare']['diamonds']];
    }

    private function validDay(string $day): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, $this->timezone);
        return $date !== false && $date->format('Y-m-d') === $day;
    }

    private function date(int $now): \DateTimeImmutable { return (new \DateTimeImmutable('@' . (string) intdiv($now, 1000)))->setTimezone($this->timezone); }
    private function nextReset(int $now): int { return $this->date($now)->modify('tomorrow')->setTime(0, 0)->getTimestamp() * 1000; }

    private function snapshotState(?string $raw, int $now, string $claimedDay): array
    {
        // Only an account without any snapshot has initial reward ledgers.
        $save = $raw === null ? ['dailyCheck' => self::EMPTY_DAILY, 'dailyShare' => self::EMPTY_SHARE]
            : json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        return ['daily_check' => $this->dailyState($save['dailyCheck'] ?? null, $now),
            'daily_share' => $this->shareState($save['dailyShare'] ?? null, $now, $claimedDay)];
    }

    private function response(int $userId, array $head, array $receipt, int $now): array
    {
        return ['player_id' => (string) $userId, 'namespace' => $this->saves->namespace(), 'request_id' => $receipt['request_id'],
            'revision' => (string) $head['revision'], 'current_revision' => (string) $head['revision'],
            'awarded_revision' => (string) $receipt['revision'], 'saved_at_ms' => (int) $head['saved_at_ms'],
            'server_time_ms' => $now, 'next_daily_reset_ms' => $this->nextReset($now), 'save' => $head['snapshot'],
            'reward' => json_decode($receipt['reward_json'], true, 64, JSON_THROW_ON_ERROR)]
            + $this->snapshotState($head['snapshot'], $now, $this->latestShareDay($userId));
    }

    private function validateRequest(string $kind, array $data): void
    {
        $expected = ['base_revision', 'request_id'];
        if ($kind === 'daily') $expected[] = 'day_index';
        elseif ($kind === 'redeem') $expected[] = 'code';
        elseif (!in_array($kind, ['offline', 'share'], true)) throw new \InvalidArgumentException('Unsupported reward kind');
        sort($expected); $keys = array_keys($data); sort($keys);
        if ($keys !== $expected || !is_string($data['request_id'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $data['request_id'])
            || !is_string($data['base_revision'] ?? null) || !preg_match('/^(0|[1-9][0-9]{0,15})$/D', $data['base_revision'])
            || (int) $data['base_revision'] >= 9007199254740991
            || ($kind === 'daily' && (!is_int($data['day_index']) || $data['day_index'] < 0 || $data['day_index'] > 6))
            || ($kind === 'redeem' && (!is_string($data['code']) || $data['code'] === '' || strlen($data['code']) > 128 || trim($data['code']) !== $data['code']))) {
            throw new GameException('REWARD_INVALID', '奖励请求格式无效', 422);
        }
    }
}
