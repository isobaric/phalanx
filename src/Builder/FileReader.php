<?php
namespace Isobaric\Phalanx\Builder;

use Exception;
use Isobaric\Phalanx\FileNotFoundException;
use Isobaric\Phalanx\Handler\Container;

class FileReader
{
    /**
     * @var string
     */
    private $applicationRoot;

    /**
     * @var string
     */
    private $namespace;

    /**
     * @param string $applicationRoot
     * @param string $namespace
     */
    public function __construct(string $applicationRoot, string $namespace)
    {
        $this->applicationRoot = rtrim($applicationRoot, '/') . '/';

        $this->namespace = $namespace . '\\';

        Container::setNamespace($this->namespace);

        Container::setApplicationPath($this->applicationRoot);
    }

    /**
     * --------------------------------------------------------------------------
     *  读取路由配置
     * --------------------------------------------------------------------------
     */
    public function readAndSetRoute(bool $isCommand)
    {
        if ($isCommand) {
            return;
        }
        $this->readProgramFile($this->applicationRoot . 'Route/');
    }

    /**
     * --------------------------------------------------------------------------
     * 设置服务的配置信息
     * --------------------------------------------------------------------------
     */
    public function readAndSetEnv()
    {
        $env = $this->readIniFile($this->applicationRoot . 'env.ini');
        if (empty($env)) {
            throw new FileNotFoundException('missing env file');
        }

        // 配置文件目录
        Container::setConfigPath($this->applicationRoot . 'Config/');

        // env.ini文件内容以数组格式保留
        Container::setEnv($env);
        unset($env);
    }

    /**
     * --------------------------------------------------------------------------
     *  设置服务基础配置
     * --------------------------------------------------------------------------
     */
    public function setFramework()
    {
        $config = Container::getConfig();
        if (!isset($config['app'])) {
            throw new FileNotFoundException('missing config: app');
        }

        // 日志格式
        if (in_array($config['app']['log_type'], ['string', 'json'])) {
            Container::setLogType($config['app']['log_type']);
        } else {
            Container::setLogType('string');
        }

        // debug
        if ($config['app']['debug'] === true) {
            Container::setDebug(true);
            error_reporting(-1);
        }

        // 请求信息是否写入日志【默认值true】
        if ($config['app']['request_log'] === false) {
            Container::setRequestLog(false);
        }

        // 日志记录的字段
        if (is_array($config['app']['request_log_fields'])) {
            Container::setRequestLogField($config['app']['request_log_fields']);
        }

        // 日志记录排除的字段
        if (is_array($config['app']['request_log_exclude'])) {
            Container::setRequestLogExclude($config['app']['request_log_exclude']);
        }

        // 日志目录、日志文件
        if (is_dir($config['app']['log_path'])) {
            Container::setLogPath('/' . trim($config['app']['log_path'], '/') . '/');
        } else {
            if (!is_dir($this->applicationRoot . 'Log')) {
                throw new FileNotFoundException('missing log path');
            }
            Container::setLogPath($this->applicationRoot . 'Log/');
        }
    }

    /**
     * --------------------------------------------------------------------------
     *  设置全局配置信息
     * --------------------------------------------------------------------------
     */
    public function readSetConfig()
    {
        $data = $this->readProgramFile(Container::getConfigPath());

        foreach ($data as $filename => $config) {
            Container::setConfig($filename, $config);
        }
    }

    /**
     * --------------------------------------------------------------------------
     *  自动读取 Interceptor 目录下的类文件 并作为拦截器使用
     * --------------------------------------------------------------------------
     *
     * 1. 以小驼峰格式的文件名称作为拦截器名称
     *
     * 2. 类中必须有handle方法
     *
     * 3. handle方法必须有一个Request类型的参数
     *
     * 4. Request类全路径：Isobaric\Core\Drawer\Request
     */
    public function readSetInterceptor(bool $isCommand)
    {
        if ($isCommand) {
            return;
        }
        if (is_file($this->applicationRoot . '/Config/interceptor.php')) {
            $interceptor = require $this->applicationRoot . '/Config/interceptor.php';
            if (is_array($interceptor)) {
                Container::setInterceptor($interceptor);
            }
        }
    }

    /**
     * 获取ini文件值
     *
     * @param string $filename
     * @return array
     */
    private function readIniFile(string $filename): array
    {
        $iniData = parse_ini_file($filename, true, INI_SCANNER_RAW);
        if ($iniData === false) {
            return [];
        }
        foreach ($iniData as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    $iniData[$key][$k] = $this->iniValueConvert($v);
                }
            } else {
                $iniData[$key] = $this->iniValueConvert($value);
            }
        }
        return $iniData;
    }

    /**
     * ini文件值转换为php变量类型
     *
     * @param string $value
     * @return bool|float|int|string|null
     */
    private function iniValueConvert(string $value)
    {
        // true
        if (strtolower($value) === 'true') {
            return true;
        }

        // false
        if (strtolower($value) === 'false') {
            return false;
        }

        // null
        if (strtolower($value) === 'null') {
            return null;
        }

        // 数字
        if (is_numeric($value)) {
            if (strpos($value, '.') === false) {
                return intval($value);
            } else {
                return doubleval($value);
            }
        } else {
            return $value;
        }
    }

    /**
     * 读取文件内容
     *
     * @param string $path
     * @return array
     */
    private function readProgramFile(string $path): array
    {
        if (!is_dir($path)) {
            throw new FileNotFoundException('missing file path');
        }

        $response = [];
        $handle = opendir($path);
        while (false !== $file = readdir($handle)) {
            if ($file == '.' || $file == '..' || !is_file($path . $file)) {
                continue;
            } else {
                try {
                    $pathInfo = pathinfo($file);
                    if ($pathInfo['extension'] != 'php') {
                        continue;
                    }
                    // 生成以文件名为下标的数组
                    $response[$pathInfo['filename']] = require_once $path . $file;
                } catch (Exception $e){
                    continue;
                }
            }
        }
        closedir($handle);
        return $response;
    }
}
