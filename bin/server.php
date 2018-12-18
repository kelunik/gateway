<?php

use Amp\Http\Server\Options;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Server;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Loop;
use Amp\Socket;
use Kelunik\Gateway\AccessLogMiddleware;
use Kelunik\Gateway\CgiRequestHandler;
use League\CLImate\CLImate;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Log\LogLevel;
use function Amp\ByteStream\getStdout;
use function Amp\Http\Server\Middleware\stack;

require __DIR__ . '/../vendor/autoload.php';

$climate = new CLImate;
$climate->arguments->add([
    'script' => [
        'prefix' => 's',
        'longPrefix' => 'script',
        'description' => 'Front controller script path.',
        'required' => true,
    ],
    'document-root' => [
        'prefix' => 'd',
        'longPrefix' => 'document-root',
        'description' => 'Document root path.',
        'required' => true,
    ],
    'envionment' => [
        'prefix' => 'e',
        'longPrefix' => 'environment',
        'description' => 'Environment, e.g. "development" / "production"',
        'defaultValue' => 'production',
        'required' => false,
    ],
    'listen' => [
        'prefix' => 'l',
        'longPrefix' => 'listen',
        'description' => 'Addresses to bind to.',
        'defaultValue' => '127.0.0.1:8000,[::1]:8000',
        'required' => false,
    ],
]);

try {
    $climate->arguments->parse($argv);
} catch (Exception $e) {
    $climate->error($e->getMessage());
    exit(1);
}

Loop::run(function () use ($climate) {
    $documentRoot = $climate->arguments->get('document-root');
    $script = $climate->arguments->get('script');
    $environment = $climate->arguments->get('environment');
    $listenAddrs = \array_map('trim', \explode(',', $climate->arguments->get('listen')));

    $sockets = [];
    foreach ($listenAddrs as $listenAddr) {
        $sockets[] = Socket\listen($listenAddr);
    }

    $serverOptions = new Options;

    if ($environment === 'development') {
        $serverOptions = $serverOptions->withDebugMode();
    }

    $cgiHandler = new CgiRequestHandler($documentRoot, $script);

    $staticFileHandler = new DocumentRoot($documentRoot);
    $staticFileHandler->setFallback($cgiHandler);

    $requestHandler = new CallableRequestHandler(function (Request $request) use ($cgiHandler, $staticFileHandler) {
        if ($request->getUri()->getPath() === '/' || \substr($request->getUri()->getPath(), -4) === '.php') {
            return $cgiHandler->handleRequest($request);
        }

        return $staticFileHandler->handleRequest($request);
    });

    $streamHandler = new StreamHandler(getStdout(), LogLevel::INFO);
    $streamHandler->setFormatter(new ConsoleFormatter(null, null, true, false));
    $streamHandler->pushProcessor(new PsrLogMessageProcessor);

    $logger = new Logger('server');
    $logger->pushHandler($streamHandler);

    $server = new Server($sockets, stack($requestHandler, new AccessLogMiddleware($logger)), $logger, $serverOptions);

    yield $server->start();

    $shutdownHandler = function (string $watcherId) use ($server, $logger) {
        Amp\Loop::cancel($watcherId);
        $logger->info('Received shutdown signal, shutting down now...');
        yield $server->stop();
        $logger->info('Server stopped, good bye.');
        Loop::stop();
    };

    Loop::onSignal(SIGINT, $shutdownHandler);
    Loop::onSignal(SIGTERM, $shutdownHandler);
});