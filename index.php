<?php

use Ycoya\GuzzlehttpResume\ClientResume;
use Ycoya\GuzzlehttpResume\Log;

require 'vendor/autoload.php';
// require_once(__DIR__ ."/src/ClientResume.php");
Log::$debug = true;

// var_dump(__DIR__);exit;
$client = new ClientResume();
$url = "http://virtualrepomaven2.com.test?file=V_20230702_195006_ES6.mp4";

$client->downloadSize = 1024*1024*100;
$client->setFilename('V_20230702_195006_ES6.mp4');
$options = [
    'verify' => false,
];
echo "starting download" . PHP_EOL;
$client->resume('GET', $url, $options);
echo "stopping download". PHP_EOL;

