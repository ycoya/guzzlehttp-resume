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

    protected string $filename;

    protected ResponseInterface $response;

    public int $downloadSize = 100 * 1024 * 1024;

    public string $rangeUnit = "bytes";


    public function __construct(protected ?Client $client = null)
    {
        if (!$this->client) {
            $this->client = new Client();
        }
        $this->stack = HandlerStack::create();
        $this->stack->push(Middleware::retry($this->reTry(), $this->delayBetweenRequest()));
        $this->stack->push(Middleware::mapRequest($this->requestMiddleware()));
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
    }

    public function getHandler(): HandlerStack
    {
        return $this->stack;
    }

    public function setFilename(string $filename): void
    {
        $this->filename = $filename;
        $this->makePath($this->filename);
    }

    public function resume($method, $url, $options): void
    {
        $options['handler'] = $this->stack;
        if (Log::$debug) {
            $options['on_stats'] = $options['on_stats'] ?? function (TransferStats $stats) {
                $this->dumpStats($stats);
            };
        }
        $this->client->request($method, $url, $options);
    }

    private function delayBetweenRequest(): callable
    {
        return function ($retries) {
            return 500;
        };
    }

    protected function requestMiddleware()
    {
        return function (RequestInterface $request) {
            Log::debug("log", "starting request middleware");
            if (empty($this->filename)) {
                $this->createFilenameFromRequest($request);
                $this->makePath($this->filename);
                Log::debug("log", "file wasn't set, creating filename from request: $this->filename");
            }
            return $request->withHeader("Range", $this->getRangeForRequest());
        };
    }

    protected function reTry()
    {
        return function (
            $retries,
            RequestInterface $request,
            ResponseInterface $response = null,
            \Exception $exception = null
        ) {
            Log::debug("log", "starting retry middleware");

            if (!is_null($response) && $response->getStatusCode() == 206) {

                list($end, $filesize) = $this->parseResponseHeaderRange($response);
                if ($end + 1 == $filesize) {
                    Log::debug("log", " download finished, retries: $retries" . PHP_EOL);
                    $this->savingFileFromResponse($response);
                    rename("$this->filename.part", $this->filename);
                    return false;
                }

                $this->savingFileFromResponse($response);
                Log::debug("log", " saving partial data" . PHP_EOL);
                return true;
            }
            $this->savingFileFromResponse($response, false);
            Log::debug("log", " retries: $retries, saving file, location:end of method for RetryMiddleware " . $response->getStatusCode() . PHP_EOL);
            return false;


        };
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
        if (is_file("$this->filename.part")) {
            $rangeStart = filesize("$this->filename.part") + 1;
            $rangeEnd   = $rangeStart + $this->downloadSize;
            $range = "$this->rangeUnit=$rangeStart-$rangeEnd";
            Log::debug("log", "filename exist, setting range: $range");
            return $range;
        }
        $range = "$this->rangeUnit=0-$this->downloadSize";
        Log::debug("log", "filename doesn't exist, setting range: $range");
        return $range;
    }

    private function createFilenameFromRequest(RequestInterface $request)
    {
        $filename = pathinfo($request->getUri())['filename'];
        $filename = $request->getUri()->getQuery();
        $filename = Header::parse($filename)[0] ?? [$request->getUri()->getPath()];
        $filename = $filename[array_key_first($filename)];
        if (!empty($filename)) {
            return $this->filename = "downloads/$filename";
        }
        return $this->filename = "downloads/" . $request->getUri()->getHost();
    }

    private function savingFileFromResponse(ResponseInterface $response, bool $partial = true): void
    {
        file_put_contents($partial ? "$this->filename.part" : $this->filename, $response->getBody(), FILE_APPEND);
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
        Log::debug($statsPath, $data);
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
