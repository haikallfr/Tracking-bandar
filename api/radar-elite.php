<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/ProbabilityFilter.php';

$items = json_decode((string) setting('radar_full_results', '[]'), true);
$items = is_array($items) ? $items : [];
$filtered = ProbabilityFilter::filter($items);

json_response([
    'ok' => true,
    'source' => [
        'generated_at' => (string) setting('radar_full_finished_at', ''),
        'count' => count($items),
    ],
    'rules' => ProbabilityFilter::rules(),
    'radar' => [
        'generated_at' => (string) setting('radar_full_finished_at', ''),
        'count' => count($filtered),
        'items' => $filtered,
    ],
]);
