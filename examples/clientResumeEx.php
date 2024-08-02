<?php

require '../vendor/autoload.php';

use Ycoya\GuzzleHttpResume\ClientResume;

$client = new ClientResume();
$url = "http://video.com.test?file=V_20230702_195006_ES6.mp4";
$options = [
    'http_errors' => false,
];
echo "starting download<br>";
$client->downloadChunkSize = 10*1024*1024;
$client->setDebug(true);
$client->resume('GET', $url, $options);
echo "stopping download<br>";


