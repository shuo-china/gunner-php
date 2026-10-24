<?php
/**
 * PHP Version 5
 * @package thirdpay
 * @author  Aaron <1020069668@qq.com>
 */
namespace thirdpay;

class Pay
{
    protected static function init($gateway, $config = [])
    {
        $gateway = preg_replace_callback('/_([a-zA-Z])/', function ($match) {
            return strtoupper($match[1]);
        }, $gateway);
        $gateway = ucfirst($gateway);
        $class   = __NAMESPACE__ . '\\gateways\\' . $gateway;
        if (class_exists($class)) {
            if (empty($config)) {
                $config = require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . "config.php");
                if (!array_key_exists($gateway, $config)) {
                    throw new \Exception("There are no config parameter for $gateway in the config.php");
                }
                $config = $config[$gateway];
            } 
            $app = new $class($config);
            return $app;
        }
        throw new \Exception("Class $class not found");
    }

    public static function __callStatic($gateway, $arguments)
    {
        $config = empty($arguments) ? [] : $arguments[0];
        return self::init($gateway, $config);
    }
}