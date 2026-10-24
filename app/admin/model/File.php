<?php

namespace app\admin\model;

class File extends BaseModel
{
    public function getSizeAttr($value)
    {
        return file_size_format($value);
    }

    public function getPathAttr($value)
    {
        return get_full_path($value);
    }

    /**
     * 根据附件Key获取名称
     * @param string $key 附件Key
     * @return string 名称
     */
    public function getFileName($key)
    {
        return $this->where('key', $key)->value('name');
    }
}