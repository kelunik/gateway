<?php

namespace Kelunik\Gateway;

use Amp\Http\Rfc7230;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Process\Process;
use Amp\Promise;
use Amp\Sync\LocalSemaphore;
use Amp\Sync\Lock;
use function Amp\ByteStream\buffer;
use function Amp\call;

final class CgiRequestHandler implements RequestHandler
{
    private $documentRoot;
    private $scriptName;
    private $scriptFilename;

    /** @var LocalSemaphore */
    private $concurrentRequestLock;

    public function __construct(string $documentRoot, string $scriptFilename)
    {
        if (\strpos($scriptFilename, $documentRoot) !== 0) {
            throw new \RuntimeException('Script is outside of the given document root');
        }

        $this->documentRoot = $documentRoot;
        $this->scriptName = \substr($scriptFilename, \strlen($documentRoot));
        $this->scriptFilename = $scriptFilename;
        $this->concurrentRequestLock = new LocalSemaphore(32);
    }

    public function handleRequest(Request $request): Promise
    {
        return call(function () use ($request) {
            /** @var $lock Lock */
            $lock = yield $this->concurrentRequestLock->acquire();

            try {
                $body = yield $request->getBody()->buffer();

                $env = [];

                $env['AUTH_TYPE'] = $this->getAuthType($request);
                $env['CONTENT_LENGTH'] = \strlen($body);
                $env['CONTENT_TYPE'] = $request->getHeader('content-type') ?? '';
                $env['GATEWAY_INTERFACE'] = 'CGI/1.1';
                $env['PATH_INFO'] = '';
                $env['PATH_TRANSLATED'] = '';
                $env['QUERY_STRING'] = $request->getUri()->getQuery();
                $env['REMOTE_ADDR'] = $request->getClient()->getRemoteAddress();
                $env['REMOTE_PORT'] = $request->getClient()->getRemotePort();
                $env['SERVER_PROTOCOL'] = 'HTTP/' . $request->getProtocolVersion();
                $env['REMOTE_HOST'] = $env['REMOTE_ADDR'];
                // $env['REMOTE_USER'] = ''; // TODO Support
                $env['REQUEST_METHOD'] = $request->getMethod();
                $env['REQUEST_URI'] = $request->getUri()->getPath();
                $env['SERVER_NAME'] = $request->getHeader('host') ?? $request->getClient()->getLocalAddress();
                $env['SERVER_SOFTWARE'] = 'kelunik/gateway';
                $env['SCRIPT_NAME'] = $this->scriptName;
                $env['SCRIPT_FILENAME'] = $this->scriptFilename;
                $env['REDIRECT_STATUS'] = 200;

                foreach ($request->getHeaders() as $headerName => $headerArray) {
                    if (\strtolower($headerName) === 'proxy') {
                        continue;
                    }

                    $env['HTTP_' . \strtoupper(\str_replace('-', '_', $headerName))] = $this->mergeHeader($headerName, $headerArray);
                }

                $env['PATH'] = \getenv('PATH');
                $env['PWD'] = \getenv('PWD');

                $process = new Process(['php-cgi', '-f', $this->scriptFilename], $this->documentRoot, $env);

                yield $process->start();
                yield $process->getStdin()->write($body);

                $response = yield buffer($process->getStdout());
                $errors = yield buffer($process->getStderr());

                $exitStatus = yield $process->join();

                if ($exitStatus !== 0) {
                    throw new \RuntimeException('Invalid exit code: ' . $exitStatus . "\r\n" . $errors);
                }

                list($rawHeaders, $responseBody) = \preg_split('(\r?\n\r?\n)', $response, 2) + [null, null];

                /** @noinspection CascadeStringReplacementInspection */
                $rawHeaders = \str_replace("\n", "\r\n", \str_replace("\r\n", "\n", $rawHeaders)); // normalize newlines

                $responseHeaders = Rfc7230::parseHeaders($rawHeaders . "\r\n");

                $rawStatus = $responseHeaders['status'][0] ?? '200';
                $responseStatus = (int) (\strstr($rawStatus, ' ', true) ?: $rawStatus);
                unset($responseHeaders['status']);

                return new Response($responseStatus, $responseHeaders, $responseBody);
            } finally {
                $lock->release();
            }
        });
    }

    private function getAuthType(Request $request): string
    {
        $auth = $request->getHeader('authorization');
        if ($auth === null || \strpos($auth, ' ') === false) {
            return '';
        }

        return \strstr($auth, ' ', true);
    }

    private function mergeHeader(string $headerName, array $headerArray): string
    {
        if (\count($headerArray) === 0) {
            return '';
        }

        if (\count($headerArray) === 1) {
            return $headerArray[0];
        }

        if ($headerName === 'cookie') {
            return \implode('; ', $headerArray);
        }

        return \implode(', ', $headerArray);
    }
}