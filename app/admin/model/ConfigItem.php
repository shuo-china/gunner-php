<?php

namespace app\admin\model;

class ConfigItem extends BaseModel
{
    public function getOptionsAttr($value)
    {
        return $value ? parse_attr($value) : [];
    }

    public function setOptionsAttr($value)
    {
        return unparse_attr($value);
    }

    public function getValueAttr($value, $data)
    {
        switch ($data['type']) {
            case 'number':
                return (int) $value;

            case 'switch':
                return $value == '1' ? true : false;

            case 'tags':
            case 'checkbox':
                return $value ? explode(',', $value) : [];

            case 'image':
            case 'file':
                return get_file($value);

            case 'images':
            case 'files':
                return get_files($value);

            default:
                return $value;
        }
    }

    public function setValueAttr($value, $data)
    {
        switch ($data['type']) {
            case 'switch':
                return $value ? '1' : '0';

            case 'tags':
            case 'checkbox':
                return $value ? implode(',', $value) : '';

            default:
                return $value;
        }
    }
}