<?php
declare(strict_types=1);

namespace app\common\game\service;

use app\common\game\exception\GameException;
use think\facade\Db;

/** Snapshot sync with CAS; reward ledgers are immutable through ordinary uploads. */
final class CloudSaveService
{
    public const MAX_BYTES = 2097152;
    private string $namespace;

    public function __construct()
    {
        $this->namespace = (string) config('game.save_namespace', 'production');
        if (!preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $this->namespace)) throw new \RuntimeException('Invalid save namespace');
    }

    public function read(int $userId): array
    {
        $row = $this->query('game_save', $userId)->find();
        return ['player_id' => (string) $userId, 'namespace' => $this->namespace,
            'revision' => (string) ($row['revision'] ?? '0'), 'save' => $row['snapshot'] ?? null,
            'saved_at_ms' => (int) ($row['saved_at_ms'] ?? 0), 'server_time_ms' => GameSessionService::nowMs()];
    }

    public function write(int $userId, array $data): array
    {
        $keys = array_keys($data); sort($keys);
        $expected = array_key_exists('resume', $data) ? ['base_revision', 'request_id', 'resume', 'save'] : ['base_revision', 'request_id', 'save'];
        if ($keys !== $expected || (array_key_exists('resume', $data) && $data['resume'] !== true) || !is_string($data['request_id'])
            || !preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $data['request_id'])
            || !is_string($data['base_revision']) || !preg_match('/^(0|[1-9][0-9]{0,15})$/D', $data['base_revision'])
            || (int) $data['base_revision'] >= 9007199254740991 || !is_string($data['save'])) {
            throw new GameException('SAVE_INVALID', '存档请求格式无效', 422);
        }
        $this->validateSnapshot($data['save']);
        $hash = hash('sha256', $data['base_revision'] . "\n" . $data['save']);
        return Db::transaction(function () use ($userId, $data, $hash) {
            // A stable parent lock also serializes simultaneous first saves (no row yet).
            $user = Db::name('user')->where('id', $userId)->lock(true)->find();
            if (!$user || (int) $user['status'] !== 1 || $user['delete_time'] !== null) {
                throw new GameException('ACCOUNT_DISABLED', '账号不可用', 403);
            }
            $current = $this->query('game_save', $userId)->lock(true)->find();
            $revision = (int) ($current['revision'] ?? 0);
            $receipt = $this->query('game_save_request', $userId)->where('request_id', $data['request_id'])->find();
            if ($receipt) {
                if (!hash_equals($receipt['payload_hash'], $hash)) throw new GameException('REQUEST_ID_CONFLICT', '保存请求已被用于另一份数据', 409);
                return $this->receipt($userId, $data['request_id'], (int) $receipt['revision'], (int) $receipt['saved_at_ms'], $revision);
            }
            if ((string) $revision !== $data['base_revision']) throw new GameException('SAVE_CONFLICT', '其他设备已更新进度，请选择要使用的存档', 409);
            if ($revision >= 9007199254740990) throw new GameException('SAVE_INVALID', '存档版本超出支持范围', 422);
            $this->assertRewardLedgersUnchanged($current['snapshot'] ?? null, $data['save']);
            $now = GameSessionService::nowMs();
            // Startup catch-up may replace the snapshot but cannot manufacture or rewind a server checkpoint.
            $anchor = ($data['resume'] ?? false) && $current ? (int) $current['saved_at_ms'] : $now;
            $record = $this->commitLocked($userId, $current, $data['save'], $now, $anchor);
            $next = (int) $record['revision'];
            Db::name('game_save_request')->insert(['user_id' => $userId, 'namespace' => $this->namespace,
                'request_id' => $data['request_id'], 'payload_hash' => $hash, 'revision' => $next, 'saved_at_ms' => $now]);
            // Expired retries still fail CAS; they can never replay an old snapshot over newer data.
            $this->query('game_save_request', $userId)->where('saved_at_ms', '<', $now - 30 * 86400000)->delete();
            return $this->receipt($userId, $data['request_id'], $next, $now, $next);
        });
    }

    public function query(string $table, int $userId)
    {
        return Db::name($table)->where('user_id', $userId)->where('namespace', $this->namespace);
    }

    public function namespace(): string { return $this->namespace; }

    /** Caller must hold the stable user lock inside the same transaction. */
    public function commitLocked(int $userId, ?array $current, string $raw, int $now, ?int $savedAtOverride = null): array
    {
        $this->validateSnapshot($raw);
        $next = (int) ($current['revision'] ?? 0) + 1;
        if ($next >= 9007199254740991) throw new GameException('SAVE_INVALID', '存档版本超出支持范围', 422);
        $day = (int) floor($now / 86400000);
        $record = ['user_id' => $userId, 'namespace' => $this->namespace, 'revision' => $next,
            'snapshot' => $raw, 'saved_at_ms' => $savedAtOverride ?? $now];
        if ($current) $this->query('game_save', $userId)->update($record);
        else Db::name('game_save')->insert($record);
        $daily = !$this->query('game_save_history', $userId)->where('utc_day', $day)->where('is_daily', 1)->find();
        Db::name('game_save_history')->insert($record + ['utc_day' => $day, 'is_daily' => $daily ? 1 : 0]);
        $this->query('game_save_history', $userId)->where('revision', '>', 1)->where('revision', '<=', $next - 20)
            ->where(function ($q) use ($day) { $q->where('is_daily', 0)->whereOr('utc_day', '<', $day - 13); })->delete();
        return $record;
    }

    private function assertRewardLedgersUnchanged(?string $before, string $after): void
    {
        // The first snapshot establishes the ledgers. Later snapshots cannot erase or advance them.
        if ($before === null) return;
        $old = json_decode($before, true, 64, JSON_THROW_ON_ERROR);
        $next = json_decode($after, true, 64, JSON_THROW_ON_ERROR);
        foreach (['dailyCheck', 'redeemedCodes', 'dailyShare'] as $key) {
            if (!array_key_exists($key, $old) || !array_key_exists($key, $next)
                || $this->canonicalLedger($old[$key]) !== $this->canonicalLedger($next[$key])) {
                throw new GameException('REWARD_STATE_CONFLICT', '奖励记录已由服务器管理，请重新同步存档', 409);
            }
        }
    }

    private function canonicalLedger($value)
    {
        if (!is_array($value)) return $value;
        foreach ($value as &$item) $item = $this->canonicalLedger($item);
        unset($item);
        ksort($value);
        return $value;
    }

    private function receipt(int $userId, string $requestId, int $revision, int $savedAt, int $current): array
    {
        return ['player_id' => (string) $userId, 'namespace' => $this->namespace, 'request_id' => $requestId,
            'revision' => (string) $revision, 'current_revision' => (string) $current, 'saved_at_ms' => $savedAt];
    }

    public function validateSnapshot(string $raw): void
    {
        if (strlen($raw) > self::MAX_BYTES) throw new GameException('SAVE_TOO_LARGE', '存档超出大小限制', 413);
        try { $save = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { throw new GameException('SAVE_INVALID', '存档 JSON 无效', 422); }
        if (!is_array($save) || ($save['version'] ?? null) !== 3 || !isset($save['money'], $save['levels'], $save['weapons'], $save['progress'])
            || !is_array($save['levels']) || count($save['levels']) !== 3 || array_keys($save['levels']) !== [0, 1, 2]
            || !is_array($save['money']) || !is_string($save['money']['digits'] ?? null)
            || !preg_match('/^[0-9]+$/D', $save['money']['digits']) || strlen($save['money']['digits']) > 100000
            || !is_int($save['money']['exponent'] ?? null) || abs($save['money']['exponent']) > 9007199254740991
            || !is_array($save['weapons']) || ($save['weapons']['testTimeOffsetMs'] ?? 0) !== 0 || !is_array($save['progress'])) {
            throw new GameException('SAVE_INVALID', '存档结构无效', 422);
        }
        foreach ($save['levels'] as $level) {
            if (!is_int($level) || $level < 0 || $level > 10000) throw new GameException('SAVE_INVALID', '升级数据无效', 422);
        }
        // Persist the original string; JSON decoding must never round or normalize game big numbers.
    }
}
