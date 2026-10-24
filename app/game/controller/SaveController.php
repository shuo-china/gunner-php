<?php
declare(strict_types=1);

namespace app\game\controller;

use app\common\game\exception\GameException;
use app\common\game\service\CloudSaveService;

final class SaveController extends \app\common\BaseController
{
    public function read()
    {
        return json((new CloudSaveService())->read((int) $this->request->gameSession['user_id']), 200, ['Cache-Control' => 'no-store']);
    }

    public function write()
    {
        $body = $this->request->getInput();
        // JSON escaping can expand a snapshot up to six bytes per source byte.
        if (strlen($body) > CloudSaveService::MAX_BYTES * 6 + 1024) throw new GameException('SAVE_TOO_LARGE', '存档请求过大', 413);
        if (stripos((string) $this->request->header('Content-Type', ''), 'application/json') !== 0) throw new GameException('SAVE_INVALID', '请提交 JSON', 422);
        $data = json_decode($body, true, 8);
        if (!is_array($data)) throw new GameException('SAVE_INVALID', '存档请求格式无效', 422);
        return json((new CloudSaveService())->write((int) $this->request->gameSession['user_id'], $data), 200, ['Cache-Control' => 'no-store']);
    }
}
