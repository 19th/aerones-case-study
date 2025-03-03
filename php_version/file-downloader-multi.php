#!/usr/bin/env php
<?php
require __DIR__.'/vendor/autoload.php';

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SingleCommandApplication;
use Symfony\Component\Console\Logger\ConsoleLogger;

(new SingleCommandApplication())
->setCode(function (InputInterface $input, OutputInterface $output) {
        $logger = new ConsoleLogger($output);
        $urls = [
            "https://storage.googleapis.com/public_test_access_ae/output_20sec.mp4",
            // Add more URLs here
        ];
        $outputDir = "completed";
        $tempDir = "temp";
        $logger->info('Starting downloads...');
        downloadFiles($urls, $outputDir, $tempDir, $logger);
    })
    ->run();

function downloadFiles(array $urls, string $outputDir, string $tempDir, LoggerInterface $logger) 
{
    $multiHandle = curl_multi_init();
    $filePointers = [];
    $curlHandles = [];

    foreach ($urls as $url) {
        $parsedUrl = parse_url($url);
        $fileName = basename($parsedUrl['path']);
        $tempPath = $tempDir . DIRECTORY_SEPARATOR . $fileName;
        $outputPath = $outputDir . DIRECTORY_SEPARATOR . $fileName;

        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $filePointer = fopen($tempPath, 'a+');
        $filePointers[$url] = $filePointer;
        $initialSize = filesize($tempPath);

        $curlHandle = curl_init($url);
        curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlHandle, CURLOPT_TIMEOUT, 5);
        curl_setopt($curlHandle, CURLOPT_NOPROGRESS, false);
        curl_setopt($curlHandle, CURLOPT_PROGRESSFUNCTION, function (
            $_,
            $download_size,
            $downloaded
        ) use ($logger, $fileName, &$initialSize) {
            if ($download_size > 0) {
                $totalDownloaded = $initialSize + $downloaded;
                $progress = ($totalDownloaded / ($initialSize + $download_size)) * 100;
                $barWidth = 50;
                $completed = round($progress / 100 * $barWidth);
                $remaining = $barWidth - $completed;
                printProgressBar($logger, $completed, $remaining, $progress, $fileName);
            }
        });

        if ($initialSize > 0) {
            fseek($filePointer, 0, SEEK_END);
            $logger->info("Resuming download of file '{$fileName}'. Initial size: {$initialSize} bytes.");
            curl_setopt($curlHandle, CURLOPT_RANGE, "bytes={$initialSize}-");
        } else {
            $logger->info("Downloading file '{$fileName}', starting from 0 bytes.");
        }

        curl_setopt($curlHandle, CURLOPT_WRITEFUNCTION, function($_, $data) use ($filePointer) {
            $written = fwrite($filePointer, $data);
            return $written;
        });

        $curlHandles[$url] = $curlHandle;
        curl_multi_add_handle($multiHandle, $curlHandle);
    }

    $running = null;
    do {
        $status = curl_multi_exec($multiHandle, $running);
        if ($status > 0) {
            $logger->error('Curl multi exec error: ' . curl_multi_strerror($status));
            break;
        }
        curl_multi_select($multiHandle);
    } while ($running > 0);

    foreach ($curlHandles as $url => $curlHandle) {
        $parsedUrl = parse_url($url);
        $fileName = basename($parsedUrl['path']);
        $tempPath = $tempDir . DIRECTORY_SEPARATOR . $fileName;
        $outputPath = $outputDir . DIRECTORY_SEPARATOR . $fileName;

        if (curl_errno($curlHandle) === 0) {
            rename($tempPath, $outputPath);
            $logger->info("Downloaded file '{$fileName}' successfully.");
        } else {
            $logger->critical("Failed to download file '{$fileName}'.");
        }

        curl_multi_remove_handle($multiHandle, $curlHandle);
        curl_close($curlHandle);
        fclose($filePointers[$url]);
    }

    curl_multi_close($multiHandle);
}

function printProgressBar(LoggerInterface $logger, int $completed, int $remaining, float $progress, string $fileName) 
{
    static $lastOutput = '';

    $progressBar = "\033[0;32m" . str_repeat('=', max(0, $completed)) . "\033[0m" . str_repeat(' ', max(0, $remaining));
    $currentOutput = "{$fileName} Progress: [{$progressBar}] " . round($progress) . "% \n";

    if ($currentOutput !== $lastOutput) {
        $logger->info($currentOutput);
        $lastOutput = $currentOutput;
    }
}
