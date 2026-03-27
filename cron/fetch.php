<?php

declare(strict_types=1);

$baseUrl = getenv('TRACKING_BANDAR_BASE_URL') ?: 'http://127.0.0.1:8099';
$response = file_get_contents(rtrim($baseUrl, '/') . '/api/refresh.php');

if ($response === false) {
    fwrite(STDERR, "Refresh gagal.\n");
    exit(1);
}

echo $response . PHP_EOL;
