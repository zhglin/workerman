<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Workerman;

/**
 * Autoload.
 */
class Autoloader
{
    /**
     * Autoload root path.
     *
     * @var string
     */
    protected static $_autoloadRootPath = '';

    /**
     * Set autoload root path.
     *
     * @param string $root_path
     * @return void
     */
    public static function setRootPath($root_path)
    {
        self::$_autoloadRootPath = $root_path;
    }

    /**
     * Load files by namespace.
     *
     * @param string $name
     * @return boolean
     */
    public static function loadByNamespace($name)
    {
        // 用/替换掉\
        $class_path = str_replace('\\', DIRECTORY_SEPARATOR, $name);
        // 如果$name是在Workerman命名空间下  class_file就在当前目录 workerman的源码都在workerman命名空间下
        if (strpos($name, 'Workerman\\') === 0) {
            $class_file = __DIR__ . substr($class_path, strlen('Workerman')) . '.php';
        } else {
            if (self::$_autoloadRootPath) {
                $class_file = self::$_autoloadRootPath . DIRECTORY_SEPARATOR . $class_path . '.php';
            }
            if (empty($class_file) || !is_file($class_file)) {
                $class_file = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . "$class_path.php";
            }
        }

        if (is_file($class_file)) {
            require_once($class_file);
            if (class_exists($name, false)) {
                return true;
            }
        }
        return false;
    }
}

//注册自动加载
spl_autoload_register('\Workerman\Autoloader::loadByNamespace');