<?php

namespace app\admin\controller;

use app\common\File;
use app\admin\model\File as FileModel;

class FileController extends BaseController
{
    protected $middleware = [
        'admin_auth'
    ];

    public function upload($key = 'file')
    {
        $uploadedFile = $this->request->file($key);
        if (!$uploadedFile) {
            $this->error(400, '未找到上传文件');
        }
        $fileIns = new File($uploadedFile);

        $rules = [
            'maxSize' => $fileIns->isImage() ? config('sys.upload.image_size') * 1024 : config('sys.upload.file_size') * 1024,
            'allowExt' => $fileIns->isImage() ? config('sys.upload.image_ext') : config('sys.upload.file_ext')
        ];

        if (!$fileIns->check($rules)) {
            $this->error(403, $fileIns->errorMessage, 'FILE_LIMIT');
        }

        $fileInfo = $fileIns->save();

        if ($fileInfo['is_image'] == true && config('sys.upload.is_thumb') == 1) {
            $thumbWidth = config('sys.upload.thumb_width') ?? 0;
            $thumbHeight = config('sys.upload.thumb_height') ?? 0;
            $thumbQuality = config('sys.upload.thumb_quality') ?? 75;
            $thumbExt = config('sys.upload.thumb_ext') ?? 'jpg';
            $fileInfo['path'] = $this->createThumb($fileInfo['path'], $thumbWidth, $thumbHeight, $thumbQuality, $thumbExt);
            $fileInfo['extension'] = $thumbExt;
        }

        $file = FileModel::create($fileInfo);

        $this->success(201, $file);
    }

    protected function createThumb($path = '', $width = 0, $height = 0, $quality = 75, $fileExt = 'jpg')
    {
        $phyPath = app()->getRootPath() . 'public' . $path;

        if (!file_exists($phyPath)) {
            return false;
        }

        $fileDir = pathinfo($phyPath, PATHINFO_DIRNAME);

        $fileName = md5(microtime(true)) . '.' . $fileExt;

        $imageInfo = \think\Image::open($phyPath);

        $imgWidth = $imageInfo->width();

        $imgHeight = $imageInfo->height();

        // 定宽等比缩放
        if ($width > 0 && $height == 0) {
            $height = (int) $imgHeight / $imgWidth * $width;
        } // 定高等比缩放
        else if ($height > 0 && $width == 0) {
            $width = (int) $imgWidth / $imgHeight * $height;
        }

        \think\Image::open($phyPath)->thumb($width, $height)->save($fileDir . DIRECTORY_SEPARATOR . $fileName, $fileExt, $quality);

        $dir = substr($fileDir, strripos($fileDir, '/'));

        unlink($phyPath);

        return '/uploads/images' . $dir . '/' . $fileName;
    }

    public function delete($key)
    {
        $file = FileModel::where('key', $key)->find();

        $filename = public_path() . $file->getData('path');

        @unlink($filename);

        $file->delete();

        $this->success(204);
    }
}
