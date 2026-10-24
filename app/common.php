<?php
if (!function_exists('get_full_path')) {
    /**
     * 格式化为全地址路径
     * @param string $path 图片路径
     */
    function get_full_path($path)
    {
        if (!preg_match('/^http[s]{0,1}:\/\//', $path)) {
            $domain = request()->domain();
            return strpos($path, '/') === 0 ?
                $domain . $path :
                $domain . '/' . $path;
        }
        return $path;
    }
}

if (!function_exists('get_file')) {
    /**
     * 批量获取附件路径
     * @param string $key 附件key
     * @return object | null
     */
    function get_file($key = '')
    {
        if (!$key) {
            return null;
        }

        $file = new \app\admin\model\File();

        $data = $file->where('key', $key)->field('id,key,path,name')->find();

        return $data;
    }
}

if (!function_exists('get_files')) {
    /**
     * 批量获取附件路径
     * @param array | string $keys 附件key
     * @return array
     */
    function get_files($keys = '')
    {
        !is_array($keys) && $keys = explode(',', $keys);

        if (!$keys) {
            return [];
        }

        $file = new \app\admin\model\File();
        $list = $file->where('key', 'in', $keys)->field('id,key,path,name')->select()->toArray();
        return $list;
    }
}

if (!function_exists('parse_attr')) {
    /**
     * 解析配置
     * @param string $value 配置值
     * @return array|string
     */
    function parse_attr($value = '')
    {
        $array = preg_split('/[,;\r\n]+/', trim($value, ",;\r\n"));
        if (strpos($value, ':')) {
            $value = array();
            foreach ($array as $val) {
                list($k, $v) = explode(':', $val);
                $value[] = [
                    'label' => $k,
                    'value' => $v,
                ];
            }
        } else {
            $value = $array;
        }
        return $value;
    }
}

if (!function_exists('unparse_attr')) {
    /**
     * 反解析配置（保持换行）
     * @param array $value
     * @return string
     */
    function unparse_attr($value = [])
    {
        if (!is_array($value)) {
            return '';
        }

        // label/value 结构
        if (isset($value[0]) && is_array($value[0]) && isset($value[0]['label'], $value[0]['value'])) {
            $lines = [];
            foreach ($value as $item) {
                $lines[] = $item['label'] . ':' . $item['value'];
            }
            return implode("\n", $lines);
        }

        // 普通数组
        return implode("\n", $value);
    }
}

if (!function_exists('dict_label')) {
    /**
     * 获取字典文本
     * @param string $key 字典类型Key
     * @param mixed $value 字典值
     * @param mixed $default 默认值
     * @return mixed
     */
    function dict_label(string $key, $value, $default = '')
    {
        return \app\common\Dict::label($key, $value, $default);
    }
}

if (!function_exists('dict_options')) {
    /**
     * 获取字典选项
     * @param string|array|null $keys 字典类型Key
     * @return array
     */
    function dict_options($keys = null): array
    {
        return \app\common\Dict::options($keys);
    }
}

if (!function_exists('file_size_format')) {
    /**
     * 文件大小格式化
     * @param $bytes 文件大小（字节 Byte)
     * @return string
     */
    function file_size_format($bytes)
    {
        $type = ['B', 'KB', 'MB', 'GB', 'TB'];
        for ($i = 0; $bytes >= 1024; $i++) {
            if ($i === 4)
                break;
            $bytes /= 1024;
        }
        return (floor($bytes * 100) / 100) . $type[$i];
    }
}

if (!function_exists('get_client_ip')) {
    /**
     * 获取客户端IP地址
     * @return string
     */
    function get_client_ip()
    {
        return request()->ip();
    }
}

if (!function_exists('thinkphp_version')) {
    /**
     * 获取ThinkPHP版本
     * @return string
     */
    function thinkphp_version()
    {
        return \think\facade\App::version();
    }
}

if (!function_exists('dumps')) {
    /**
     * 浏览器友好的变量输出
     * @param mixed $vars 要输出的变量
     * @return void
     */
    function dumps(...$vars)
    {
        ob_start();
        var_dump(...$vars);

        $output = ob_get_clean();
        $output = preg_replace('/\]\=\>\n(\s+)/m', '] => ', $output);

        if (PHP_SAPI == 'cli') {
            $output = PHP_EOL . $output . PHP_EOL;
        } else {
            if (!extension_loaded('xdebug')) {
                $output = htmlspecialchars($output, ENT_SUBSTITUTE);
            }
            $output = '<pre>' . $output . '</pre>';
        }

        echo $output;
    }
}
