<?php

namespace Ycoya\GuzzleHttpResume\Helpers;

class Utils
{
    /**
     * https://www.binarytides.com/php-create-nested-directories-for-a-given-path/
     *
     * @param string $path
     * @return void
     */
    public static function makePath($path)
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
}
