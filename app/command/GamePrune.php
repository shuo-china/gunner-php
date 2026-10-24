<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;
use app\common\game\service\GameSessionService;

final class GamePrune extends Command
{
    protected function configure() { $this->setName('game:prune')->setDescription('Remove expired login sessions and rate-limit buckets'); }
    protected function execute(Input $input, Output $output)
    {
        $sessions = Db::name('game_session')->where('expires_at_ms', '<=', GameSessionService::nowMs())->delete();
        $buckets = Db::name('game_login_throttle')->where('expires_at', '<', time() - 60)->delete();
        $output->writeln("Removed $sessions expired sessions and $buckets expired login buckets.");
        return 0;
    }
}
