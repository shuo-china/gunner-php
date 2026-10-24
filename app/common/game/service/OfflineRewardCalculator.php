<?php
declare(strict_types=1);

namespace app\common\game\service;

use app\common\game\exception\GameException;

/** Runs a fixed local bundle, never a command or argument supplied by the client. */
final class OfflineRewardCalculator
{
    public function calculate(object $save, int $lastSavedAt, int $now): array
    {
        $worker = root_path() . 'bin/reward-worker.cjs';
        if (!is_file($worker) || !function_exists('proc_open')) throw $this->unavailable();
        $input = json_encode(['save' => $save, 'last_saved_at_ms' => $lastSavedAt, 'now_ms' => $now],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        // File descriptors avoid pipe deadlocks for large snapshots on Windows and Linux.
        $files = [tmpfile(), tmpfile(), tmpfile()];
        if (in_array(false, $files, true)) { foreach ($files as $file) if (is_resource($file)) fclose($file); throw $this->unavailable(); }
        $process = null;
        try {
            fwrite($files[0], $input); rewind($files[0]);
            $pipes = [];
            $process = @proc_open([(string) config('game.node_binary', 'node'), $worker],
                [0 => $files[0], 1 => $files[1], 2 => $files[2]], $pipes, root_path(), null, ['bypass_shell' => true]);
            if (!is_resource($process)) throw $this->unavailable();
            $deadline = microtime(true) + 10;
            do {
                $status = proc_get_status($process);
                if (!$status['running']) break;
                if (microtime(true) >= $deadline || fstat($files[1])['size'] > 3 * 1024 * 1024 || fstat($files[2])['size'] > 65536) {
                    proc_terminate($process); throw $this->unavailable();
                }
                usleep(10000);
            } while (true);
            $exit = proc_close($process); $process = null;
            if (($status['exitcode'] === -1 ? $exit : $status['exitcode']) !== 0) throw $this->unavailable();
            rewind($files[1]);
            $raw = stream_get_contents($files[1], 3 * 1024 * 1024 + 1);
            if (strlen($raw) > 3 * 1024 * 1024) throw $this->unavailable();
            $result = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
            if (!is_object($result->save ?? null) || !is_object($result->reward ?? null)
                || ($result->reward->kind ?? null) !== 'offline') throw $this->unavailable();
            return ['save' => $result->save, 'reward' => $result->reward];
        } catch (GameException $e) { throw $e; }
        catch (\Throwable $e) { throw $this->unavailable(); }
        finally {
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
            foreach ($files as $file) fclose($file);
        }
    }

    private function unavailable(): GameException { return new GameException('REWARD_CALCULATION_FAILED', '离线收益核算暂不可用，请稍后重试', 503); }
}
