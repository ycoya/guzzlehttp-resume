<?php
namespace Ycoya\GuzzleHttpResume;


use Carbon\Carbon;
use Ycoya\GuzzleHttpResume\Helpers\Utils;

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
        Utils::makePath("debugFolder/$folderName.log");
        $date = self::getDate('Y-m-d H:i:s');
        $dateLog = self::getDate('Y-m-d');
        file_put_contents("debugFolder/$folderName-$dateLog.log", "$date----$folderName$info" . PHP_EOL, FILE_APPEND);
    }




    private static function getDate($format)
    {
        return (new \DateTime())->format($format);
    }
}
