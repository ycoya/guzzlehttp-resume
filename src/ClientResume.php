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

    private bool $requestComplete = false;

    /** @var array{start: int, end: int, resource: string, etag: ?string, total: ?int}|null */
    private ?array $requestedRange = null;

    protected string $downloadFolder = "downloads";


    public function __construct(protected ?Client $client = null)
    {
        if (!$this->client) {
            $this->client = new Client();
        }
        $this->setHandler(HandlerStack::create());
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
        $this->removeRequiredMiddleware();
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

    public function setDebugVerbose(bool $debug)
    {
        Log::$debug = $debug;
        Log::$debugVerbose = $debug;
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
        $this->requestComplete = false;
        $this->requestedRange = null;
        $options['handler'] = $this->stack;
        $options['decode_content'] = false;
        $options['sink'] = null;
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


    private function removeRequiredMiddleware()
    {
        $this->stack->remove('resume_download');
        $this->stack->remove('update_range_header');
    }

    private function addRequiredMiddleware()
    {
        $this->stack->after('http_errors', Middleware::retry($this->reTry(), $this->delayBetweenRequest()), 'resume_download');
        $this->stack->after('prepare_body', Middleware::mapRequest($this->requestMiddleware()), 'update_range_header');
    }

    protected function requestMiddleware()
    {
        return function (RequestInterface $request) {
            Log::debug("log", "::requestMiddleware: starting request middleware, url: " . $request->getUri());
            if (empty($this->filePath)) {
                Log::debug("log", "::requestMiddleware: file wasn't set, composing filename from request");
                $this->createFilePathFromRequest($request);
                Log::debug("log", "::requestMiddleware: filename created: $this->filePath");
            }
            $request = $request->withoutHeader('If-Range')->withHeader('Accept-Encoding', 'identity');
            $this->requestedRange = null;
            if ($this->requestComplete) {
                return $request->withoutHeader('Range');
            }

            // The URI may contain credentials or signed query parameters.
            $resource = hash('sha256', (string) $request->getUri());
            $path = $this->partialPath();
            clearstatcache(true, $path);
            $hasPartial = is_file($path);
            $partial = $hasPartial ? $this->readPartial($resource) : null;
            if ($hasPartial && $partial === null) {
                return $request->withoutHeader('Range');
            }

            if ($this->downloadChunkSize < 1) {
                throw new \InvalidArgumentException('The download chunk size must be positive.');
            }
            $start = $partial['size'] ?? 0;
            $total = $partial['total'] ?? null;
            $limit = $total === null ? PHP_INT_MAX : $total - 1;
            $end = $start + min($this->downloadChunkSize - 1, $limit - $start);
            $etag = $partial['etag'] ?? null;
            $this->requestedRange = compact('start', 'end', 'resource', 'etag', 'total');

            $request = $request->withHeader('Range', "$this->rangeUnit=$start-$end");
            return $etag === null ? $request : $request->withHeader('If-Range', $etag);
        };
    }


    protected function reTry()
    {
        return function (
            $retries,
            RequestInterface $request,
            ?ResponseInterface $response = null,
            ?\Exception $exception = null
        ) {
            if ($response === null) {
                return false;
            }
            if ($response->getStatusCode() === 206) {
                return $this->handlePartialResponse($response);
            }
            if ($response->getStatusCode() === 416 && $this->requestedRange !== null) {
                // An empty or shortened file may no longer satisfy the requested range.
                $this->requestComplete = true;
                return true;
            }
            if ($response->getStatusCode() === 200) {
                $this->saveCompleteResponse($response);
            }
            return false;
        };
    }

    private function partialPath(): string
    {
        return "$this->filePath.$this->partialExtension";
    }

    private static function isStrongEtag(string $etag): bool
    {
        // Keep only strong tags that can be stored verbatim in JSON.
        return preg_match('/\A"[\x21\x23-\x7e]*"\z/', $etag) === 1;
    }

    /** @return array{size: int, total: int, etag: string, resource: string}|null */
    private function readPartial(string $resource): ?array
    {
        $path = $this->partialPath();
        $metadata = $path . '.json';
        if (!is_file($metadata) || ($contents = file_get_contents($metadata)) === false) {
            return null;
        }
        $partial = json_decode($contents, true);
        if (!is_array($partial) || ($partial['resource'] ?? null) !== $resource
            || !is_string($partial['etag'] ?? null) || !self::isStrongEtag($partial['etag'])
            || !is_int($partial['size'] ?? null) || !is_int($partial['total'] ?? null)
            || $partial['size'] <= 0 || $partial['size'] >= $partial['total']
            || filesize($path) !== $partial['size']
        ) {
            return null;
        }
        return $partial;
    }

    private function handlePartialResponse(ResponseInterface $response): bool
    {
        $request = $this->requestedRange;
        if ($request === null) {
            throw new \UnexpectedValueException('Received a partial response without requesting a range.');
        }
        $etag = $response->getHeaderLine('ETag');
        if ($request['etag'] !== null && $etag !== $request['etag']) {
            // The origin returned 206 without confirming the saved representation.
            $this->requestComplete = true;
            return true;
        }

        $header = $response->getHeaderLine('Content-Range');
        if (preg_match('/\Abytes ([0-9]+)-([0-9]+)\/([0-9]+)\z/i', $header, $range) !== 1) {
            throw new \UnexpectedValueException('Invalid Content-Range on a partial response.');
        }
        $start = self::byteCount($range[1]);
        $end = self::byteCount($range[2]);
        $total = self::byteCount($range[3]);
        if ($start !== $request['start'] || $end < $start || $end > $request['end']
            || $end >= $total || ($request['total'] !== null && $total !== $request['total'])
        ) {
            throw new \UnexpectedValueException('Content-Range does not match the requested range.');
        }

        $size = $end + 1;
        if ($size < $total && !self::isStrongEtag($etag)) {
            $this->requestComplete = true;
            return true;
        }

        $path = $this->partialPath();
        $metadata = $path . '.json';
        if ($start === 0) {
            $this->makeDestinationDirectory();
            self::removeFile($metadata);
        }
        $this->writeBody($response, $path, $size - $start, $start);

        if ($size === $total) {
            $this->publishFile($path);
            return false;
        }

        $resource = $request['resource'];
        $checkpoint = json_encode(compact('resource', 'etag', 'size', 'total'), JSON_THROW_ON_ERROR);
        if (file_put_contents($metadata, $checkpoint, LOCK_EX) !== strlen($checkpoint)) {
            throw new \RuntimeException('Could not save the download checkpoint.');
        }
        return true;
    }

    private static function byteCount(string $value): int
    {
        if (preg_match('/\A[0-9]+\z/', $value) !== 1) {
            throw new \UnexpectedValueException('Invalid HTTP byte count.');
        }
        $digits = ltrim($value, '0');
        $digits = $digits === '' ? '0' : $digits;
        $count = (int) $digits;
        if ((string) $count !== $digits) {
            throw new \UnexpectedValueException('HTTP byte count exceeds the supported file size.');
        }
        return $count;
    }

    private function makeDestinationDirectory(): void
    {
        if (!Utils::makePath($this->filePath)) {
            throw new \RuntimeException('Could not create the download directory.');
        }
    }

    private function saveCompleteResponse(ResponseInterface $response): void
    {
        $length = $response->hasHeader('Content-Length')
            ? self::byteCount($response->getHeaderLine('Content-Length'))
            : null;
        $this->makeDestinationDirectory();
        $path = tempnam(dirname($this->filePath), '.download-');
        if ($path === false) {
            throw new \RuntimeException('Could not create a temporary download file.');
        }
        try {
            $this->writeBody($response, $path, $length);
            $this->publishFile($path);
        } finally {
            self::removeFile($path);
        }
    }

    private function writeBody(ResponseInterface $response, string $path, ?int $length, int $offset = 0): void
    {
        $encoding = strtolower($response->getHeaderLine('Content-Encoding'));
        if ($encoding !== '' && $encoding !== 'identity') {
            throw new \UnexpectedValueException('Expected an identity-encoded download.');
        }
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $file = fopen($path, $offset === 0 ? 'wb' : 'r+b');
        if ($file === false) {
            throw new \RuntimeException('Could not open the download file for writing.');
        }

        $restoreOffset = false;
        try {
            if ($offset > 0 && ((fstat($file)['size'] ?? null) !== $offset || fseek($file, $offset) !== 0)) {
                throw new \RuntimeException('The partial download changed while receiving the range.');
            }
            $restoreOffset = true;
            $written = 0;
            while (!$body->eof()) {
                $chunk = $body->read(8192);
                $size = strlen($chunk);
                if ($size === 0 && $body->eof()) {
                    break;
                }
                if ($length !== null && $size > $length - $written) {
                    throw new \UnexpectedValueException('Response body exceeds its declared byte count.');
                }
                if ($size === 0 || fwrite($file, $chunk) !== $size) {
                    throw new \RuntimeException('Could not write the complete response body.');
                }
                $written += $size;
            }
            if ($length !== null && $written !== $length) {
                throw new \UnexpectedValueException('Response body is shorter than its declared byte count.');
            }
            if (!fflush($file)) {
                throw new \RuntimeException('Could not flush the download file.');
            }
        } catch (\Throwable $error) {
            if ($restoreOffset) {
                ftruncate($file, $offset);
            }
            throw $error;
        } finally {
            fclose($file);
        }
    }

    private function publishFile(string $path): void
    {
        if (!rename($path, $this->filePath)) {
            throw new \RuntimeException('Could not move the completed download into place.');
        }
        self::removeFile($this->partialPath());
        self::removeFile($this->partialPath() . '.json');
    }

    private static function removeFile(string $path): void
    {
        clearstatcache(true, $path);
        if (is_file($path) && !unlink($path)) {
            throw new \RuntimeException('Could not remove a temporary download file.');
        }
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

    private function dumpStats(TransferStats $stats)
    {
        $request = $stats->getRequest();
        $response = $stats->getResponse();
        $data = "::dumpStats  url: " .  $request->getUri() . " headers: " . json_encode($request->getHeaders()) . " -----responseStatus:"  . $response?->getStatusCode() . " responseHeaders: " . json_encode($response?->getHeaders()  );
        if (Log::$debugVerbose) {
            $data .= " body: " . $response?->getBody();
        }
        $data .= PHP_EOL;

        $statsPath = "stats/guzzle_stats";
        Log::debug($statsPath, $data);
    }

}
