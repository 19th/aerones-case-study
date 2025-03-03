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
        $url = "https://storage.googleapis.com/public_test_access_ae/output_20sec.mp4";
        $outputDir = "completed";
        $tempDir = "temp";
        $logger->info('Starting download...');
        downloadFile($url, $outputDir, $tempDir, $logger);
    })
    ->run();


function downloadFile(string $url, string $outputDir, string $tempDir, LoggerInterface $logger) 
{
    $parsedUrl = parse_url($url);
    $fileName = basename($parsedUrl['path']);
    $tempPath = $tempDir . DIRECTORY_SEPARATOR . $fileName;
    $outputPath = $outputDir . DIRECTORY_SEPARATOR . $fileName;

    # create directories, make accessible
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0777, true);
    }
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0777, true);
    }

    $filePointer = fopen($tempPath, 'a+');
    $curlHandle = curl_init($url);
    $initialSize = filesize($tempPath);

    curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curlHandle, CURLOPT_TIMEOUT, 5);
    curl_setopt($curlHandle, CURLOPT_NOPROGRESS, false);
    curl_setopt($curlHandle, CURLOPT_PROGRESSFUNCTION, function (
        $resource,
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

    # proceed with resuming download if file exists
    if ($initialSize > 0) {
        fseek($filePointer, 0, SEEK_END);
        $logger->info("Resuming download of file '{$fileName}'. Initial size: {$initialSize} bytes.");
        curl_setopt($curlHandle, CURLOPT_RANGE, "bytes={$initialSize}-");
    } else {
        $logger->info("Downloading file '{$fileName}', starting from 0 bytes.");
    }

    # write data to the file using same pointer whenever data is received
    curl_setopt($curlHandle, CURLOPT_WRITEFUNCTION, function($curl, $data) use ($filePointer) {
        $written = fwrite($filePointer, $data);
        return $written;
    });

    $attempts = 5;
    $succeeded = retryWithBackoff(function() use ($curlHandle, $filePointer, &$initialSize) {
        curl_setopt($curlHandle, CURLOPT_RESUME_FROM, ftell($filePointer));
        $initialSize = ftell($filePointer);
        return curl_exec($curlHandle);
    }, $logger, $attempts, 1);

    curl_close($curlHandle);
    fclose($filePointer);

    if ($succeeded) {
        rename($tempPath, $outputPath);
        $logger->info("Downloaded file '{$fileName}' successfully.");
    } else {
        $logger->critical("Failed to download file '{$fileName}' after {$attempts} attempts.");
    }
}

function retryWithBackoff(callable $callback, LoggerInterface $logger, int $attempts = 3, int $backoffSeconds = 1) 
{
    $retry = 0;
    $backoff = $backoffSeconds;

    while ($retry < $attempts) {
        $result = $callback();
        if ($result !== false) {
            return $result;
        }

        $retry++;
        if ($retry > $attempts) {
            return false;
        }
        $logger->info("Retrying in {$backoff} seconds...\n");
        sleep($backoff);
        $backoff *= 2;
    }

    return false;
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