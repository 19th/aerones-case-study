#!/usr/bin/env php
<?php
require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SingleCommandApplication;
use Symfony\Component\Console\Logger\ConsoleLogger;
use GuzzleHttp\Client;
use GuzzleHttp\Promise;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Utils;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\RequestInterface;

(new SingleCommandApplication())
    ->setCode(function (InputInterface $input, OutputInterface $output) {
        $logger = new ConsoleLogger($output);

        $urls = [
            'https://storage.googleapis.com/public_test_access_ae/output_20sec.mp4',
            'https://storage.googleapis.com/public_test_access_ae/output_30sec.mp4',
            'https://storage.googleapis.com/public_test_access_ae/output_40sec.mp4',
            'https://storage.googleapis.com/public_test_access_ae/output_50sec.mp4',
            'https://storage.googleapis.com/public_test_access_ae/output_60sec.mp4',
        ];
        $outputDir = 'completed';
        $tempDir = 'temp';

        // uncomment for debugging purposes
        // array_map('unlink', glob("{$outputDir}/*"));
        // array_map('unlink', glob("{$tempDir}/*"));

        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $logger->info('Starting download...');
        downloadFiles($urls, $outputDir, $tempDir, $logger);
        $logger->info('Download finished.');
    })
    ->run();

function downloadFiles(array $urls, string $outputDir, string $tempDir, LoggerInterface $logger): void
{
    $handlerStack = HandlerStack::create();
    $handlerStack->push(addRetryHandler($logger, 10));
    $handlerStack->push(addRangeHeader($tempDir));

    $client = new Client([
        'timeout' => 5,
        'handler' => $handlerStack,
    ]);

    $promises = [];
    foreach ($urls as $url) {
        $promises[] = downloadFileAsync($client, $url, $outputDir, $tempDir, $logger);
    }

    Promise\Utils::settle($promises)->wait();
}

function downloadFileAsync(Client $client, string $url, string $outputDir, string $tempDir, LoggerInterface $logger): Promise\PromiseInterface
{
    $parsedUrl = parse_url($url);
    $fileName = basename($parsedUrl['path']);
    $tempPath = $tempDir . DIRECTORY_SEPARATOR . $fileName;
    $outputPath = $outputDir . DIRECTORY_SEPARATOR . $fileName;

    // use "a" mode to append to the file if it already exists
    $resource = Utils::tryFopen($tempPath, 'a');
    $stream = Utils::streamFor($resource);

    return $client->getAsync($url, [
        'sink' => $stream,
        'progress' => function ($downloadTotal, $downloadedBytes) use ($logger, $fileName, $stream) {
            if ($downloadTotal > 0) {
                static $initialDownloadTotal = null;
                if ($initialDownloadTotal === null) {
                    $initialDownloadTotal = $downloadTotal;
                }
                // after retry, the downloadTotal will be reset to the remaining bytes
                $progress = ($stream->tell() / $initialDownloadTotal) * 100;
                $barWidth = 50;
                $completed = round($progress / 100 * $barWidth);
                $remaining = $barWidth - $completed;
                printProgressBar($logger, $completed, $remaining, $progress, $fileName);
            }
        },
    ])->then(
        function () use ($tempPath, $outputPath) {
            rename($tempPath, $outputPath);
        },
        function ($reason) use ($fileName, $logger) {
            $logger->critical("Failed to download {$fileName}: {$reason->getMessage()}");
        }
    );
}

function printProgressBar(LoggerInterface $logger, int $completed, int $remaining, float $progress, string $fileName): void
{
    // hacky-wacky way to decrease log noise
    static $previousOutput = [];

    $progressBar = "\033[0;32m" . str_repeat('=', max(0, $completed)) . "\033[0m" . str_repeat(' ', max(0, $remaining));
    $currentOutput = "{$fileName} Progress: [{$progressBar}] " . round($progress) . "% \n";

    if ($currentOutput !== ($previousOutput[$fileName] ?? '')) {
        $previousOutput[$fileName] = $currentOutput;
        $logger->info($currentOutput);
    }
}

function addRetryHandler(LoggerInterface $logger, $maxRetries = 5)
{
    return Middleware::retry(
        function ($retries, $request, $response, $exception) use ($logger, $maxRetries) {
            if ($retries >= $maxRetries) {
                $logger->info("Max retry count reached: {$retries}");
                return false;
            }

            if ($response && $response->getStatusCode() >= 500 || $exception instanceof ConnectException) {
                $logger->error('Retrying download due to: ' . ($exception ? $exception->getMessage() : 'server error'));
                $logger->info("Retry: {$retries}");
                return true;
            }
            return false;
        },
        function ($retries) {
            // exponential backoff, but 10 seconds is the max
            return min(2 ** $retries, 10);
        }
    );
}

function addRangeHeader(string $fileDir)
{
    return function (callable $handler) use ($fileDir) {
        return function (
            RequestInterface $request,
            array $options
        ) use ($handler, $fileDir) {
            $fileName = basename($request->getUri()->getPath());
            $filePath = $fileDir . DIRECTORY_SEPARATOR . $fileName;
            if (file_exists($filePath)) {
                $fileSize = filesize($filePath);
                $request = $request->withHeader('Range', "bytes={$fileSize}-");
            }
            return $handler($request, $options);
        };
    };
}
