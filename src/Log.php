<?php
namespace Ycoya\GuzzlehttpResume;


use Carbon\Carbon;

class Log
{
    public static $debug = false;

    public static $debugVerbose = false;

    public static function debug($folderName, $info)
    {
        if(self::$debug) {
            self::_debug($folderName, $info);
        }
    }

    public static function debugVerbose($folderName, $info)
    {
        if(self::$debugVerbose) {
            self::_debug($folderName, $info);
        }
    }


    private static function _debug($folderName, $info)
    {
        self::makePath("debug/$folderName.log");
        $date = self::getDate('Y-m-d_H-i-s');
        $dateLog = self::getDate('Y-m-d');
        file_put_contents("debug/$folderName.log", "$date----$info" . PHP_EOL, FILE_APPEND);
        file_put_contents("debug/$dateLog.log", "$date----$info" . PHP_EOL, FILE_APPEND);
    }



    protected static function makePath($path)
    {
        $dir = pathinfo($path, PATHINFO_DIRNAME);

        if (is_dir($dir)) {
            return true;
        }

        if (self::makePath($dir)) {
            if (mkdir($dir)) {
                chmod($dir, 0777);
                return true;
            }
        }


        return false;
    }

    private static function getDate($format)
    {
        return (new \DateTime())->format($format);
    }
}
