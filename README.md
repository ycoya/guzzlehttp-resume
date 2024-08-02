## About Guzzlehttp-resume

Guzzlehttp-resume is a wrapper for guzzlehttp to allow downloads by chunks. If a download is interrupted it can be resume later, without the need to start the download again from zero.
This is possible only if the server support partial requests.

It has two classes **ClientResume** and **ClientHttpResume**. The difference of these two classes is that **ClientResume** has methods
to set actions and settings, while ClientHttpResume is a wrapper of the first that allows set options similar to
guzzlehttp.

This doesn't affect the guzzlehttp main functionality, if you are already using it in your code, no drastical changes should be made, just replace the guzzlehttp with one of these classes and you should get the same results. The changes that need to be made are the new options and a new method to download by chunks. See in examples folder for a better understanding.

Here are examples using these classes.

Using **ClientResume**

```bash
<?php

require 'vendor/autoload.php';

use Ycoya\GuzzleHttpResume\ClientResume;


$client = new ClientResume();
$url = "https://video.com.test?file=V_20230702_195006_ES6.mp4";
$options = [
    'verify' => false,
];
echo "starting download<br>";
$client->downloadChunkSize = 10*1024*1024;
$client->setDebug(true);
$client->setfilePath("descarga/video.mp4");
$client->resume('GET', $url, $options);
```
Using **ClientHttpResume**
```bash
<?php

require 'vendor/autoload.php';
use Ycoya\GuzzleHttpResume\ClientHttpResume;

$url = "https://video.com.test?file=V_20230702_195006_ES6.mp4";
$options = [
   'verify' => false,
   'clientResume' => [
      'chunkSize'=> 10 * 1024 *1024,
      'filePath' => "descarga/video.mp4",
      'debug'   => true
      ]
   ];
$client = new ClientHttpResume();
$client->downloadResume('GET', $url, $options);
```

## Installation

```bash
  composer require ycoya/guzzlehttp-resume
```

## Requirements
php 8.0+
## Options
These are all the options that can be set

```bash
<?php
require 'vendor/autoload.php';

use Ycoya\GuzzleHttpResume\ClientResume;
use GuzzleHttp\Client;

$guzzleClient = new Client(['base_uri' => 'http://httpbin.org']);

$client = new ClientResume();
$client->setClient($guzzleClient);
$client->downloadChunkSize = 10*1024*1024;
$client->setfilePath("descarga/video.mp4");
$client->setPartialExtension("partFile");
$client->setHandler(GuzzleHttp\HandlerStack::create());
$client->setDebug(true);
$client->setDebugVerbose(true);
$client->resume('GET', 'video');
```

```bash
<?php
require 'vendor/autoload.php';
use Ycoya\GuzzleHttpResume\ClientHttpResume;
use GuzzleHttp\Client;

$guzzleClient = new Client(['base_uri' => 'http://httpbin.org']);
$options = [
   'clientResume' => [
      'client'   => $guzzleClient,
      'chunkSize'=> 10 * 1024 *1024,
      'filePath' => "descarga/video.mp4",
      'partialExt' => "partFile",
      'handler'   => GuzzleHttp\HandlerStack::create(),
      'debug'   => true,
      'debugVerbose'   => true,
      ]
   ];
$client = new ClientHttpResume();
$client->downloadResume('GET', 'video', $options);
```
Guzzle options can be set in the array too. All options set outside *clientResume* key are for guzzle options.

```bash
<?php
require 'vendor/autoload.php';
use Ycoya\GuzzleHttpResume\ClientHttpResume;

$options = [
   'base_uri' => 'http://httpbin.org',
   'clientResume' => [
      'chunkSize'=> 10 * 1024 *1024,
      'filePath' => "descarga/video.mp4",
      'partialExt' => "partFile",
      'handler'   => GuzzleHttp\HandlerStack::create(),
      'debug'   => true
      ]
   ];
   $client = new ClientHttpResume();
   $client->downloadResume('GET', 'video', $options);
```


When using **ClientHttpResume** class, you have to set a key named `clientResume` and inside this you set all the options needed.

#Client option

In fact, it is not necessary to set a GuzzleClient object. When **ClientHttpResume** or **ClientResume**  is created, an object for GuzzleClient
is created too, so if you need to pass options you set it outside of `clientResume` key option.
Let assume we need to set base uri option to guzzleClient. It can be set as the example above.

```bash
<?php
require 'vendor/autoload.php';
use Ycoya\GuzzleHttpResume\ClientHttpResume;

$options = [
   'base_uri' => 'http://httpbin.org',
   'clientResume' => [
          ...
      ]
   ];
$client = new ClientHttpResume();
$client->downloadResume('GET', 'video', $options);
```
But let say, that you already have a GuzzleClient object and you can't replace it by **ClientHttpResume**. For this case you can set the option
`client` and passed it to **ClientHttpResume**, it will use all the settings made in GuzzleClient object when resume or starting the download.

```bash
<?php
require 'vendor/autoload.php';
use Ycoya\GuzzleHttpResume\ClientHttpResume;
use GuzzleHttp\Client;

$guzzleClient = new Client(['base_uri' => 'http://httpbin.org']);
$options = [
   'clientResume' => [
          'client' => $guzzleClient,
           ...
      ]
   ];
$client = new ClientHttpResume();
$client->downloadResume('GET', 'video', $options);
```

#ChunkSize option

It not necessary to set this value, because **ClientHttpResume** or **ClientResume** uses a default value inside, but if you need to customize
it, you have to set this value to bytes. In the next example we set a chunkSize of 10 MB.

```bash
<?php
require 'vendor/autoload.php';
use Ycoya\GuzzleHttpResume\ClientHttpResume;

$url = "https://video.com.test?file=V_20230702_195006_ES6.mp4";
$options = [
   'clientResume' => [
           'chunkSize' => 10 * 1024*1024 // in bytes.
      ]
   ];
$client = new ClientHttpResume();
$client->downloadResume('GET', $url, $options);
```
#filePath option

This is optional too. If not filepath is provided, the application will try to build a filename by its url.
filePath is a string and it should contain filename.

```bash
<?php
require 'vendor/autoload.php';
use Ycoya\GuzzleHttpResume\ClientHttpResume;

$url = "https://video.com.test?file=V_20230702_195006_ES6.mp4";
$options = [
   'clientResume' => [
           'filePath' => "C:/downloads/video.mp4"
      ]
   ];
$client = new ClientHttpResume();
$client->downloadResume('GET', $url, $options);
```
#partialExt option

This is the extension used in the temporary download file, before complete download. By default the application used *part* as extension.
Ex: *video.mp4.part*

```bash
<?php
require 'vendor/autoload.php';
use Ycoya\GuzzleHttpResume\ClientHttpResume;

$url = "https://video.com.test?file=V_20230702_195006_ES6.mp4";
$options = [
   'clientResume' => [
           'partialExt' => "filePart"
      ]
   ];
$client = new ClientHttpResume();
$client->downloadResume('GET', $url, $options);
```

#handler option

This option is to set a GuzzleHttp\HandlerStack if needed. To know how to build it, see the docs from [guzzlehttp/guzzle](https://docs.guzzlephp.org/en/stable/handlers-and-middleware.html)

```bash
<?php
require 'vendor/autoload.php';
use Ycoya\GuzzleHttpResume\ClientHttpResume;

$url = "https://video.com.test?file=V_20230702_195006_ES6.mp4";
$handler =  GuzzleHttp\HandlerStack::create();

$options = [
   'clientResume' => [
           'handler' => $handler
      ]
   ];
$client = new ClientHttpResume();
$client->downloadResume('GET', $url, $options);
```

#debug option

The debug option enables the action of creating logs with a path debug/log-{timestamp}.log. In addition to this file is also dumped guzzle stats inside a folder named stats.

```bash
<?php
require 'vendor/autoload.php';
use Ycoya\GuzzleHttpResume\ClientHttpResume;

$url = "https://video.com.test?file=V_20230702_195006_ES6.mp4";
$handler =  GuzzleHttp\HandlerStack::create();

$options = [
   'clientResume' => [
           'debug' => true
      ]
   ];
$client = new ClientHttpResume();
$client->downloadResume('GET', $url, $options);
```

#debugVerbose option

The debugVerbose option enables to dump the whole body of the response sent by the server in log file. This option enable debug internally too.

```bash
<?php
require 'vendor/autoload.php';
use Ycoya\GuzzleHttpResume\ClientHttpResume;

$url = "https://video.com.test?file=V_20230702_195006_ES6.mp4";
$handler =  GuzzleHttp\HandlerStack::create();

$options = [
   'clientResume' => [
           'debugVerbose' => true
      ]
   ];
$client = new ClientHttpResume();
$client->downloadResume('GET', $url, $options);
```