<?php

use Ycoya\GuzzleHttpResume\ClientHttp;

// var_dump(__DIR__);exit;
require '../vendor/autoload.php';

$client = new ClientHttp(['base_uri' => 'https://www.google.com']);
try {
   $res = $client->request('GET', '/redirect/3', ['allow_redirects' => false, 'http_errors' => false, 'connect_timeout' => 3.14]);
   echo $res->getStatusCode();
} catch(\Throwable $th) {
   echo $th;
}

try {
   $response = $client->request('GET', '/foo.js', [
       'headers'        => ['Accept-Encoding' => 'gzip'],
       'decode_content' => false,
       'connect_timeout' => 3.14,
       'http_errors' => false
   ]);
   echo $response->getStatusCode();

} catch(\Throwable $th) {
    echo $th;
}

$url = "http://virtualrepomaven2.com.test?file=V_20230702_195006_ES6.mp4";
$options = [
   'clientResume' => [
      'chunkSize'=>10 * 1024 *1024,
      'filePath' => "descarga/video.mp4",
      'debug'   => true
      ]
   ];
try {
   $client->downloadResume('GET', $url, $options);
echo "good";
} catch(\Throwable $th) {
   echo $th;
}
