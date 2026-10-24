<?php

namespace app\wxapp\model;

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
}