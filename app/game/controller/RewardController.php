<?php
declare(strict_types=1);

namespace app\game\controller;

use app\common\game\exception\GameException;
use app\common\game\service\RewardService;

final class RewardController extends \app\common\BaseController
{
    public function state()
    {
        return json((new RewardService())->state((int) $this->request->gameSession['user_id']), 200, ['Cache-Control' => 'no-store']);
    }

    public function daily() { return $this->claim('daily'); }
    public function share() { return $this->claim('share'); }
    public function redeem() { return $this->claim('redeem'); }
    public function offline() { return $this->claim('offline'); }

    private function claim(string $kind)
    {
        $body = $this->request->getInput();
        if (strlen($body) > 2048 || stripos((string) $this->request->header('Content-Type', ''), 'application/json') !== 0) {
            throw new GameException('REWARD_INVALID', '请提交有效的奖励 JSON 请求', 422);
        }
        $data = json_decode($body, true, 8);
        if (!is_array($data)) throw new GameException('REWARD_INVALID', '奖励请求格式无效', 422);
        return json((new RewardService())->claim((int) $this->request->gameSession['user_id'], $kind, $data), 200, ['Cache-Control' => 'no-store']);
    }
}
