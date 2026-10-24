<?php

namespace app\common;

use app\admin\model\DictItem;
use app\admin\model\DictType;

class Dict
{
    protected const CACHE_LABELS = 'dict_labels';
    protected const CACHE_OPTIONS = 'dict_options';

    public static function label(string $key, $value, $default = '')
    {
        $dicts = cache(self::CACHE_LABELS);

        if ($dicts === null) {
            $dicts = self::buildLabels();
            cache(self::CACHE_LABELS, $dicts);
        }

        return $dicts[$key][$value] ?? $default;
    }

    public static function options($keys = null): array
    {
        $options = cache(self::CACHE_OPTIONS);

        if ($options === null) {
            $options = self::buildOptions();
            cache(self::CACHE_OPTIONS, $options);
        }

        if ($keys === null || $keys === '') {
            return $options;
        }

        if (is_string($keys)) {
            if (strpos($keys, ',') === false) {
                return $options[$keys] ?? [];
            }

            $keys = explode(',', $keys);
        }

        $keys = array_filter($keys, static function ($key) {
            return $key !== '';
        });

        return array_intersect_key($options, array_flip($keys));
    }

    public static function clear(): void
    {
        cache(self::CACHE_LABELS, null);
        cache(self::CACHE_OPTIONS, null);
    }

    protected static function buildLabels(): array
    {
        $keysById = DictType::where('status', 1)->column('key', 'id');
        $items = DictItem::where('status', 1)->order('id asc')->select()->toArray();
        $dicts = [];

        foreach ($items as $item) {
            if (!isset($keysById[$item['type_id']])) {
                continue;
            }

            $dicts[$keysById[$item['type_id']]][$item['value']] = $item['name'];
        }

        return $dicts;
    }

    protected static function buildOptions(): array
    {
        $keysById = DictType::where('status', 1)->column('key', 'id');
        $items = DictItem::where('status', 1)->order('id asc')->select()->toArray();
        $options = [];

        foreach ($items as $item) {
            if (!isset($keysById[$item['type_id']])) {
                continue;
            }

            $key = $keysById[$item['type_id']];
            $options[$key][] = [
                'label' => $item['name'],
                'value' => $item['value'],
                'color' => $item['color']
            ];
        }

        return $options;
    }
}
