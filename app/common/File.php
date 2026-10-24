<?php
namespace app\common;

class File
{
    // 文件实例化对象
    protected $file;

    // 文件信息
    protected $fileInfo = [];

    // 允许上传的大小
    protected $maxSize = 0;

    // 允许文件上传的后缀
    protected $allowExt = [];

    // 允许上传的mime类型
    protected $allowMime = [];

    // 错误代码
    protected $errorCode = 0;

    /**
     * 构造方法
     * @param \think\file\UploadedFile $file
     */
    public function __construct($file)
    {
        if (!is_object($file) || get_class($file) !== 'think\\file\\UploadedFile') {
            throw new \Exception("参数错误:file");
        }
        $this->file = $file;
    }

    /**
     * 设置选项
     * @param $key
     * @param $value
     */
    protected function setOption($key, $value)
    {
        $keys = array_keys(get_class_vars(__CLASS__));
        if (in_array($key, $keys)) {
            $this->$key = $value;
        }
    }

    /**
     * 是否为图片
     * @return bool
     */
    public function isImage()
    {
        if (getimagesize($this->file->getRealPath())) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * 检查文件
     * @param array $rules
     * @return bool
     */
    public function check($rules)
    {
        foreach ($rules as $k => $v) {
            $this->setOption($k, $v);
        }

        $this->getFileInfo();

        // 判断文件的大小,mime,后缀是否符合
        if (!$this->checkSize() || !$this->checkExt() || !$this->checkMime()) {
            return false;
        }

        return true;
    }

    /**
     * 上传文件
     * @return array
     */
    public function save()
    {
        $this->getFileInfo();

        $dirname = $this->fileInfo['is_image'] ? 'images' : 'files';

        $savename = \think\facade\Filesystem::disk('upload')->putFile($dirname, $this->file);

        $savepath = config('filesystem.disks.upload.url') . '/' . str_replace('\\', '/', $savename);

        $this->fileInfo['path'] = $savepath;

        return $this->fileInfo;
    }

    /**
     * 获取文件信息
     */
    protected function getFileInfo()
    {
        if ($this->fileInfo) {
            return;
        }

        $info = [
            'key' => uniqid(),
            'name' => $this->file->getOriginalName(),
            'mime' => $this->file->getOriginalMime(),
            'extension' => $this->file->getOriginalExtension(),
            'md5' => $this->file->md5(),
            'sha1' => $this->file->sha1(),
            'size' => $this->file->getSize(),
            'app' => app('http')->getName()
        ];

        if ($imageSize = getimagesize($this->file->getRealPath())) {
            $info['width'] = $imageSize[0];
            $info['height'] = $imageSize[1];
            $info['is_image'] = true;
        } else {
            $info['is_image'] = false;
        }

        $this->fileInfo = $info;
    }

    /**
     * 判断文件大小
     * @return bool
     */
    protected function checkSize()
    {
        if (!empty($this->maxSize) && $this->fileInfo['size'] > $this->maxSize) {
            $this->setOption('errorCode', 1);
            return false;
        }
        return true;
    }

    /**
     * 判断后缀
     * @return bool
     */
    protected function checkExt()
    {
        if (!empty($this->allowExt) && !in_array(strtolower($this->fileInfo['extension']), array_map('strtolower', $this->allowExt))) {
            $this->setOption('errorCode', 2);
            return false;
        }
        return true;
    }

    /**
     * 判断mime类型
     * @return bool
     */
    protected function checkMime()
    {
        if (!empty($this->allowMime) && !in_array(strtolower($this->fileInfo['mime']), array_map('strtolower', $this->allowMime))) {
            $this->setOption('errorCode', 3);
            return false;
        }
        return true;
    }

    /**
     * 获取错误信息
     * @return string
     */
    protected function getErrorMessage()
    {
        switch ($this->errorCode) {
            case 0:
                $message = '';
                break;
            case 1:
                $message = '文件不能超过' . file_size_format($this->maxSize);
                break;
            case 2:
                $message = '文件后缀名不支持, 当前支持：' . implode('，', $this->allowExt);
                break;
            case 3:
                $message = '文件mime类型不支持';
                break;
            default:
                $message = '未知错误';
        }
        return $message;
    }

    public function __get($name)
    {
        if ($name == 'errorCode') {
            return $this->errorCode;
        } elseif ($name == 'errorMessage') {
            return $this->getErrorMessage();
        }
    }
}