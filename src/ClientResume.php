<?php

namespace Ycoya\GuzzleHttpResume;


use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Header;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Ycoya\GuzzleHttpResume\Interfaces\ClientResumeInterface;
use Ycoya\GuzzleHttpResume\Helpers\Utils;

class ClientResume implements ClientResumeInterface
{

    private HandlerStack $stack;

    protected string $filePath;

    protected ResponseInterface $response;

    public int $downloadChunkSize = 50 * 1024 * 1024;

    protected string $partialExtension = "part";

    protected string $rangeUnit = "bytes";

    private int $prevStartRange = 0;

    protected string $downloadFolder = "downloads";


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
    }

    public function setDebug(bool $debug)
    {
        Log::$debug = $debug;
    }

    public function setPartialExtension(string $ext): void
    {
        $this->partialExtension = $ext;
    }

    public function getPartialExtension(): string
    {
        return $this->partialExtension;
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
            return 50;
        };
    }


    private function addRequiredMiddleware()
    {
        $this->stack->after('prepare_body', Middleware::retry($this->reTry(), $this->delayBetweenRequest()), 'resume_download');
        $this->stack->after('resume_download', Middleware::mapRequest($this->requestMiddleware()), 'update_range_header');
    }

    protected function requestMiddleware()
    {
        return function (RequestInterface $request) {
            Log::debug("log", "starting request middleware");
            if (empty($this->filePath)) {
                Log::debug("log", "file wasn't set, composing filename from request: $this->filePath");
                $this->createFilePathFromRequest($request);
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
            if(is_null($response)) {
                Log::debug("log", "response null, possible error in connection");
                return false;
            }

            if ($response->getStatusCode() == 206) {
                return $this->handlePartialResponse($response, $retries);
            }

            if ($response->getStatusCode() == 416) {
                Log::debug("log", " retries: $retries, StatusCode: 416 Range Not Satisfiable. ServerResponse:" . PHP_EOL);
                Log::debug("log", $response->getBody());
                return false;
            }

            if($retries == 0) {
                $this->deleteFileIfExist("$this->filePath.$this->partialExtension");
            }

            if ($response->getStatusCode() == 200) {
                Utils::makePath($this->filePath);
                $this->savingFileFromResponse($response, false, false);
            }


            Log::debug("log", " retries: $retries, saving file, location:end of method for RetryMiddleware " . $response->getStatusCode() . PHP_EOL);
            return false;

        };
    }

    private function deleteFileIfExist($filePath)
    {
        if(is_file($filePath)) {
            unlink($filePath);
        }
    }

    private function handlePartialResponse(ResponseInterface $response, $retries): bool
    {
        list($startRange, $endRange, $filesize) = $this->parseResponseHeaderRange($response);
        if($startRange == 0) {
            Utils::makePath($this->filePath);
        }

        if ($endRange + 1 == $filesize) {
            Log::debug("log", " download finished, times: $retries" . PHP_EOL);
            $this->savingFileFromResponse($response);
            rename("$this->filePath.$this->partialExtension", $this->filePath);
            return false;
        }
        Log::debug("log", " saving partial data times: $retries" . PHP_EOL);
        $this->savingFileFromResponse($response);
        return $this->isRangeUpdated($retries, $endRange);
    }

    private function parseResponseHeaderRange(ResponseInterface $response): array
    {
        $rangeHeader = $response->getHeader("Content-Range")[0];
        list($rangeUnit, $rangeData) = explode(" ", $rangeHeader);
        list($range, $filesize) = explode("/", $rangeData);
        list($start, $end) = explode("-", $range);
        return [$start, $end, $filesize];
    }

    private function isRangeUpdated($retries, $endRange): bool
    {
        if(is_int($retries/5) ) {
            if($this->prevStartRange == $endRange) {
                $this->deleteFileIfExist("$this->filePath.$this->partialExtension");
                Log::debug("log", "range wasn't updated, it could be a wrong middleware order or an error saving partial file" . PHP_EOL);
                return false;
            }
            $this->prevStartRange = $endRange;
        }
        return true;
    }


    private function getRangeForRequest()
    {
        if (is_file("$this->filePath.$this->partialExtension")) {
            clearstatcache();
            $rangeStart = filesize("$this->filePath.$this->partialExtension");
            $rangeEnd   = $rangeStart + $this->downloadChunkSize;
            $range = "$this->rangeUnit=$rangeStart-$rangeEnd";
            Log::debug("log", "partial file exist, setting range: $range");
            return $range;
        }
        $range = "$this->rangeUnit=0-$this->downloadChunkSize";
        Log::debug("log", "partial file doesn't exist, setting range: $range");
        return $range;
    }

    private function createFilePathFromRequest(RequestInterface $request)
    {
        $filename = $request->getUri()->getQuery();
        $filename = Header::parse($filename)[0] ?? [$request->getUri()->getPath()];
        $filename = $filename[array_key_first($filename)];
        if (!empty($filename)) {
            return $this->filePath = "$this->downloadFolder/$filename";
        }
        return $this->filePath = "$this->downloadFolder/" . $request->getUri()->getHost();
    }

    private function savingFileFromResponse(ResponseInterface $response, bool $partial = true, bool $fileAppend = true): void
    {
        file_put_contents($partial ? "$this->filePath.$this->partialExtension" : $this->filePath, $response->getBody(), $fileAppend ? FILE_APPEND : 0);
    }

    private function dumpStats(TransferStats $stats)
    {
        $request = $stats->getRequest();
        $response = $stats->getResponse();
        $data = "-- url: " .  $request->getUri() . " headers: " . json_encode($request->getHeaders()) . " -----responseStatus:"  . $response?->getStatusCode() . " responseHeaders: " . json_encode($response?->getHeaders()  );
        if (Log::$debugVerbose) {
            $data .= " body: " . $response->getBody() . PHP_EOL;
        } else {
            $data .= PHP_EOL;
        }
        $statsPath = "stats/guzzle_stats";
        Log::debug($statsPath, $data);
    }

}
