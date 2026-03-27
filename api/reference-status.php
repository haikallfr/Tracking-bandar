<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/ReferenceRepository.php';

$repo = new ReferenceRepository();

json_response([
    'ok' => true,
    'counts' => $repo->counts(),
]);
