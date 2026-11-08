<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Ycoya\GuzzleHttpResume\ClientResume;

require dirname(__DIR__) . '/vendor/autoload.php';

const DOWNLOAD_URL = 'https://example.test/file.bin';

function expectSame($expected, $actual): void
{
    if ($actual !== $expected) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function expectException(string $class, callable $call): void
{
    try {
        $call();
    } catch (Throwable $error) {
        if ($error instanceof $class) {
            return;
        }
        throw $error;
    }
    throw new RuntimeException("Expected $class to be thrown.");
}

function partial(int $start, int $end, string $body, ?string $etag = '"v1"'): Response
{
    $headers = ['Content-Range' => "bytes $start-$end/8"];
    if ($etag !== null) {
        $headers['ETag'] = $etag;
    }
    return new Response(206, $headers, $body);
}

function complete(string $body, ?int $length = null): Response
{
    return new Response(200, ['Content-Length' => (string) ($length ?? strlen($body))], $body);
}

function download(string $path, array $responses, array &$history, string $url = DOWNLOAD_URL): void
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history), 'history');

    $client = new ClientResume();
    $client->setHandler($stack);
    $client->setfilePath($path);
    $client->downloadChunkSize = 4;
    $client->resume('GET', $url);
}

function interruptDownload(string $path): void
{
    $history = [];
    $error = new ConnectException('Connection interrupted.', new Request('GET', DOWNLOAD_URL));
    expectException(ConnectException::class, static function () use ($path, $error, &$history) {
        download($path, [partial(0, 3, 'ABCD'), $error], $history);
    });
    expectSame('ABCD', file_get_contents($path . '.part'));
}

function expectDownload(string $expected, string $path): void
{
    expectSame($expected, file_get_contents($path));
    expectSame(false, file_exists($path . '.part'));
    expectSame(false, file_exists($path . '.part.json'));
}

$tests = [
    'changed-file' => static function (string $path) {
        interruptDownload($path);
        $history = [];
        $origin = static function (RequestInterface $request): Response {
            // With If-Range, a conforming server ignores Range when the file has changed.
            if ($request->getHeaderLine('If-Range') === '"v1"') {
                return complete('12345678');
            }
            return partial(4, 7, '5678', '"v2"');
        };
        download($path, [$origin], $history);
        expectDownload('12345678', $path);
        expectSame('"v1"', $history[0]['request']->getHeaderLine('If-Range'));
    },
    'unchanged-file' => static function (string $path) {
        interruptDownload($path);
        $history = [];
        download($path, [partial(4, 7, 'EFGH')], $history);
        expectDownload('ABCDEFGH', $path);
        expectSame('bytes=4-7', $history[0]['request']->getHeaderLine('Range'));
        expectSame('"v1"', $history[0]['request']->getHeaderLine('If-Range'));
    },
    'changed-file-between-chunks' => static function (string $path) {
        $history = [];
        download($path, [partial(0, 3, 'ABCD'), complete('12345678')], $history);
        expectDownload('12345678', $path);
        expectSame('"v1"', $history[1]['request']->getHeaderLine('If-Range'));
    },
    'server-ignores-if-range' => static function (string $path) {
        interruptDownload($path);
        $history = [];
        download($path, [partial(4, 7, '5678', '"v2"'), complete('12345678')], $history);
        expectDownload('12345678', $path);
        expectSame('"v1"', $history[0]['request']->getHeaderLine('If-Range'));
        expectSame(false, $history[1]['request']->hasHeader('Range'));
        expectSame(false, $history[1]['request']->hasHeader('If-Range'));
    },
    'old-partial-without-checkpoint' => static function (string $path) {
        file_put_contents($path . '.part', 'ABCD');
        $history = [];
        download($path, [complete('12345678')], $history);
        expectDownload('12345678', $path);
        expectSame(false, $history[0]['request']->hasHeader('Range'));
    },
    'different-url-same-path' => static function (string $path) {
        interruptDownload($path);
        $history = [];
        download($path, [complete('12345678')], $history, DOWNLOAD_URL . '?other=1');
        expectDownload('12345678', $path);
        expectSame(false, $history[0]['request']->hasHeader('Range'));
    },
    'wrong-range-start' => static function (string $path) {
        interruptDownload($path);
        $history = [];
        expectException(UnexpectedValueException::class, static function () use ($path, &$history) {
            download($path, [partial(3, 6, 'DEFG')], $history);
        });
        expectSame('ABCD', file_get_contents($path . '.part'));
        expectSame(false, file_exists($path));
    },
    'truncated-partial-response' => static function (string $path) {
        interruptDownload($path);
        $history = [];
        expectException(UnexpectedValueException::class, static function () use ($path, &$history) {
            download($path, [partial(4, 7, 'EFG')], $history);
        });
        expectSame('ABCD', file_get_contents($path . '.part'));
        expectSame(false, file_exists($path));
    },
    'truncated-complete-response' => static function (string $path) {
        file_put_contents($path, 'previous download');
        interruptDownload($path);
        $history = [];
        expectException(UnexpectedValueException::class, static function () use ($path, &$history) {
            download($path, [complete('1234', 8)], $history);
        });
        expectSame('previous download', file_get_contents($path));
        expectSame('ABCD', file_get_contents($path . '.part'));
    },
];
foreach (['missing' => null, 'weak' => 'W/"v1"'] as $type => $etag) {
    $tests[$type . '-validator'] = static function (string $path) use ($etag) {
        $history = [];
        download($path, [partial(0, 3, 'ABCD', $etag), complete('ABCDEFGH')], $history);
        expectDownload('ABCDEFGH', $path);
        expectSame(false, $history[1]['request']->hasHeader('Range'));
    };
}

if (isset($argv[1])) {
    if (!isset($tests[$argv[1]])) {
        fwrite(STDERR, "Unknown test: $argv[1]\n");
        exit(1);
    }
    $tests = [$argv[1] => $tests[$argv[1]]];
}

$failures = 0;
foreach ($tests as $name => $test) {
    $dir = sys_get_temp_dir() . '/guzzle-resume-test-' . bin2hex(random_bytes(8));
    if (!mkdir($dir, 0700)) {
        throw new RuntimeException('Could not create the test directory.');
    }
    try {
        $test($dir . '/file.bin');
        echo "PASS $name\n";
    } catch (Throwable $error) {
        fwrite(STDERR, "FAIL $name: {$error->getMessage()}\n");
        ++$failures;
    } finally {
        foreach (new FilesystemIterator($dir) as $file) {
            unlink($file->getPathname());
        }
        rmdir($dir);
    }
}

exit($failures === 0 ? 0 : 1);
