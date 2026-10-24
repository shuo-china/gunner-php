<?php
declare(strict_types=1);

namespace app\listener;

use think\facade\Config;
use app\admin\model\ConfigGroup;
use app\admin\model\ConfigItem;

class InitConfig
{
    protected const CACHE_KEY = 'sys_config';

    /**
     * 按照分组读取系统配置
     * @return array
     */
    protected function getConfigs()
    {
        $result = [];

        $groups = ConfigGroup::select()->toArray();

        foreach ($groups as $g) {
            $data = [];

            $list = ConfigItem::where('group_id', $g['id'])->select();

            foreach ($list as $item) {
                $data[$item['key']] = $item['value'];
            }

            $result[$g['key']] = $data;
        }
        return $result;
    }

    /**
     * 事件监听处理
     */
    public function handle()
    {
        $configs = cache(self::CACHE_KEY);

        if ($configs === null) {
            $configs = $this->getConfigs();
            cache(self::CACHE_KEY, $configs);
        }

        Config::set($configs, 'sys');
    }

    public static function clear(): void
    {
        cache(self::CACHE_KEY, null);
    }
}
