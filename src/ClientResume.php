<?php

namespace Ycoya\GuzzlehttpResume;

require 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Header;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Ycoya\GuzzlehttpResume\Interfaces\ClientResumeInterface;

class ClientResume implements ClientResumeInterface
{

    private HandlerStack $stack;

    protected string $filePath;

    protected ResponseInterface $response;

    public int $downloadSize = 50 * 1024 * 1024;

    public string $rangeUnit = "bytes";

    private int $prevStartRange = 0;


    public function __construct(protected ?Client $client = null)
    {
        if (!$this->client) {
            $this->client = new Client();
        }
        $this->stack = HandlerStack::create();
        $this->addRequiredMiddleware();
    }

    public function setClient(Client $client)
    {
        $this->client = $client;
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    public function setHandler(HandlerStack $handlerStack)
    {
        $this->stack = $handlerStack;
        $this->addRequiredMiddleware();
    }

    public function getHandler(): HandlerStack
    {
        return $this->stack;
    }

    public function setfilePath(string $filePath): void
    {
        $this->filePath = $filePath;
        $this->makePath($this->filePath);
    }

    public function resume($method, $url, $options = []): void
    {
        $options['handler'] = $this->stack;
        if (Log::$debug) {
            $options['on_stats'] = $options['on_stats'] ?? function (TransferStats $stats) {
                $this->dumpStats($stats);
            };
        }
        $this->client->request($method, $url, $options);
    }

    public function delayBetweenRequest(): callable
    {
        return function ($retries) {
            return 500;
        };
    }


    private function addRequiredMiddleware()
    {
        $this->stack->push(Middleware::retry($this->reTry(), $this->delayBetweenRequest()));
        $this->stack->push(Middleware::mapRequest($this->requestMiddleware()));
    }

    protected function requestMiddleware()
    {
        return function (RequestInterface $request) {
            Log::debug("log", "starting request middleware");
            if (empty($this->filePath)) {
                $this->createFilePathFromRequest($request);
                $this->makePath($this->filePath);
                Log::debug("log", "file wasn't set, creating filePath from request: $this->filePath");
            }
            return $request->withHeader("Range", $this->getRangeForRequest());
        };
    }

    protected function reTry()
    {
        return function (
            $retries,
            RequestInterface &$request,
            ResponseInterface $response = null,
            \Exception $exception = null
        ) {
            Log::debug("log", "starting retry middleware");

            if (!is_null($response) && $response->getStatusCode() == 206) {

                list($endRange, $filesize) = $this->parseResponseHeaderRange($response);
                if ($endRange + 1 == $filesize) {
                    Log::debug("log", " download finished, retries: $retries" . PHP_EOL);
                    $this->savingFileFromResponse($response);
                    rename("$this->filePath.part", $this->filePath);
                    return false;
                }
                is_dir('src');
                $this->savingFileFromResponse($response);
                Log::debug("log", " saving partial data retries: $retries" . PHP_EOL);
               file_put_contents("log12.txt"," $retries $endRange  " . $response->getStatusCode() . PHP_EOL);
                return $this->isRangeUpdated($retries, $endRange);
            }
            $this->savingFileFromResponse($response, false);
            Log::debug("log", " retries: $retries, saving file, location:end of method for RetryMiddleware " . $response->getStatusCode() . PHP_EOL);
            return false;


        };
    }

    private function isRangeUpdated($retries, $endRange): bool
    {
        if(is_int($retries/5) ) {
            if($this->prevStartRange == $endRange) {
                unlink("$this->filePath.part");
                Log::debug("log", "no range was updated, it could be middleware order" . PHP_EOL);
                return false;
            }
            $this->prevStartRange = $endRange;
        }
        return true;
    }


    private function parseResponseHeaderRange(ResponseInterface $response): array
    {
        $rangeHeader = $response->getHeader("Content-Range")[0];
        list($rangeUnit, $rangeData) = explode(" ", $rangeHeader);
        list($range, $filesize) = explode("/", $rangeData);
        list($start, $end) = explode("-", $range);
        return [$end, $filesize];
    }

    private function getRangeForRequest()
    {
        if (is_file("$this->filePath.part")) {
            $rangeStart = filesize("$this->filePath.part") + 1;
            $rangeEnd   = $rangeStart + $this->downloadSize;
            $range = "$this->rangeUnit=$rangeStart-$rangeEnd";
            Log::debug("log", "filePath exist, setting range: $range");
            return $range;
        }
        $range = "$this->rangeUnit=0-$this->downloadSize";
        Log::debug("log", "filePath doesn't exist, setting range: $range");
        return $range;
    }

    private function createFilePathFromRequest(RequestInterface $request)
    {
        $filename = pathinfo($request->getUri())['filename'];
        $filename = $request->getUri()->getQuery();
        $filename = Header::parse($filename)[0] ?? [$request->getUri()->getPath()];
        $filename = $filename[array_key_first($filename)];
        if (!empty($filename)) {
            return $this->filePath = "downloads/$filename";
        }
        return $this->filePath = "downloads/" . $request->getUri()->getHost();
    }

    private function savingFileFromResponse(ResponseInterface $response, bool $partial = true): void
    {
        file_put_contents($partial ? "$this->filePath.part" : $this->filePath, $response->getBody(), FILE_APPEND);
    }

    private function dumpStats(TransferStats $stats)
    {
        $request = $stats->getRequest();
        $response = $stats->getResponse();
        $data = "-- url: " .  $request->getUri() . " headers: " . json_encode($request->getHeaders()) . "----- responseHeaders: " . json_encode($response->getHeaders());
        if (Log::$debugVerbose) {
            $data .= " body: " . $response->getBody() . PHP_EOL;
        } else {
            $data .= PHP_EOL;
        }
        $statsPath = "stats/guzzle_stats.txt";
        // Log::$debug = true;
        Log::debug($statsPath, $data);
        // Log::$debug = false;
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
