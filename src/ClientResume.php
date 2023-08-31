<?php
namespace Ycoya\GuzzlehttpResume;

require 'vendor/autoload.php';

use DateTime;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\RetryMiddleware;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Ycoya\GuzzlehttpResume\Interfaces\ClientResumeInterface;

class ClientResume implements ClientResumeInterface
{

    private HandlerStack $stack;

    protected string $filename;

    protected ResponseInterface $response;

    public int $downloadSize = 100*1024*1024;

    public string $rangeUnit = "bytes";


    public function __construct(protected ?Client $client = null)
    {
        if(!$this->client) {
            $this->client = new Client(['base'=> "http://hola/a/b"]);
        }
        $this->stack = HandlerStack::create();
        // print_r(Middleware::retry($this->reSend()));exit;
        // $this->config = collect(['verify' => false, 'http_errors' => false,'handler' => $this->stack]);
        // $this->stack->push(Middleware::mapRequest($this->requestMiddleware()));
        // $this->stack->push(Middleware::mapResponse($this->responseHandler()));
        $this->stack->push(Middleware::retry($this->reSend(), $this->delayBetweenRequest()));
        $this->stack->push(Middleware::mapRequest($this->requestMiddleware()));
        // $this->stack->push(new RetryMiddleware($this->reSend(), $this->nextHandler()));

    }

    private function delayBetweenRequest() : callable
    {
        return function($retries) {
            return 0.5 * 1000;
        };
    }

    public function setFilename(string $filename)
    {
        $this->filename = $filename;
    }

    protected function requestMiddleware()
    {
        return function(RequestInterface $request){
            Log::debug("log", "starting request middleware");
            if(is_file("$this->filename.part")) {
                $rangeStart = filesize("$this->filename.part") + 1;
                $rangeEnd   = $rangeStart + $this->downloadSize;
                Log::debug("log", "setting range file exist: $this->rangeUnit=$rangeStart-$rangeEnd");
                return $request->withHeader("Range", "$this->rangeUnit=$rangeStart-$rangeEnd");
            }

            if (is_null($this->filename)) {
               $filename = pathinfo($request->getUri()->getQuery())['filename'];
               $this->filename = __DIR__ . (new \DateTime())->format('Y-m-d_H-i-s') . "_$filename";
            }
            Log::debug("log", "setting range no file: $this->filename");
            return $request->withHeader("Range", "$this->rangeUnit=0-$this->downloadSize");
        };
    }

    protected function responseHandler() : callable
    {
        return function(ResponseInterface $response) {
            Log::debug("log", "starting response middleware");
            $this->response = $response;
            if($response->getStatusCode() == 200) {
                //No ranges from server, whole file
                $this->makePath($this->filename);
                file_put_contents($this->filename, $response->getBody());
                Log::debug("log", "response 200 file: $this->filename");
                return $response;
            }

            if($response->getStatusCode() == 416) {
                //Invalid range
                Log::debug("log", "invalid range");
                return $response;
            }
            file_put_contents($this->filename, $response->getBody());
            Log::debug("log", "partial content " . $response->getStatusCode());
            return $response;
        };
    }

    protected function reSend()
    {
        return function ($retries,
                        RequestInterface $request,
                        ResponseInterface $response = null,
                        \Exception $exception = null
        ){
            Log::debug("log", "starting retry middleware");
            if(!is_null($response)) {
                if($response->getStatusCode() == 206) {
                    // if($retries >= 10) {
                    //     Log::debug("log", " retries: $retries, return false");
                    //     return false;
                    // }

                    $rangeHeader = $response->getHeader("Content-Range")[0];
                    list($rangeUnit, $rangeData) = explode(" ", $rangeHeader);
                    list($range, $filesize) = explode("/", $rangeData);
                    list($start, $end) = explode("-", $range);
                    if($end +1 == $filesize) {
                        Log::debug("log", " retries: $retries , finished" . PHP_EOL);
                        file_put_contents("$this->filename.part", $response->getBody(), FILE_APPEND);
                        rename("$this->filename.part", $this->filename);
                        return false;
                    }
                    file_put_contents("$this->filename.part", $response->getBody(), FILE_APPEND);

                    Log::debug("log", " retries: $retries" . PHP_EOL);
                    return true;
                }
            }
            if($retries >= 10) {
                Log::debug("log", " retries: $retries, return false");
                return false;
            }
            Log::debug("log"," retries: $retries, return true" . PHP_EOL );
            return true;

        };
    }

    private function nextHandler()
    {
        return function(RequestInterface $request, $options) {
            Log::debug('aparte.txt', json_encode($options), FILE_APPEND);
            if(is_file("$this->filename.part")) {
                $rangeStart = filesize("$this->filename.part") + 1;
                $rangeEnd   = $rangeStart + $this->downloadSize;
                Log::debug("log", "setting range in retry middleware file exist: $this->rangeUnit=$rangeStart-$rangeEnd");
                $request->withHeader("Range", "$this->rangeUnit=$rangeStart-$rangeEnd");
            }
            return $request;
        };
    }

    public function resume($method, $url, $options)
    {
        $options['handler'] = $this->stack;
         if(true) {
            $options['on_stats'] = function(TransferStats $stats) {
                $request = $stats->getRequest();
                $response = $stats->getResponse();
                $data = "-- url: ".  $request->getUri() . " headers: " . json_encode($request->getHeaders()) . "----- responseHeaders: " . json_encode($response->getHeaders());
                if(false) {
                    $data .= " body: " . $response->getBody() . PHP_EOL;
                } else {
                    $data .= PHP_EOL;
                }
                $statsPath = "debug/stats/guzzle_stats.txt";
                $this->makePath($statsPath);
                file_put_contents($statsPath, $data, FILE_APPEND);($stats);
            };
        }
        $this->client->request($method, $url, $options);
    }

     // gotten from internet.
     private function makePath($path)
     {
         $dir = pathinfo($path, PATHINFO_DIRNAME);

         if (is_dir($dir)) {
             return true;
         } else {
             if ($this->makePath($dir)) {
                 if (mkdir($dir)) {
                     chmod($dir, 0777);
                     return true;
                 }
             }
         }
         return false;
     }
}
