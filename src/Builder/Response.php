<?php

namespace Isobaric\Phalanx\Builder;

class Response
{
    /**
     * header响应内容格式
     * @var string
     */
    private static $contentType = 'application/json; charset=UTF-8';

    /**
     * 设置响应内容格式
     *
     * @param string $contentType
     */
    public static function setContentType(string $contentType = 'application/json; charset=UTF-8')
    {
        self::$contentType = $contentType;
    }

    /**
     * 内容输出
     *
     * @param $data
     */
    public static function output($data)
    {
        if (PHP_SAPI != 'cli') {
            header('Content-Type:' . self::$contentType);
        }

        if (is_string($data) || is_numeric($data)) {
            echo $data;
        } else {
            echo json_encode($data);
        }
        exit();
    }
}
