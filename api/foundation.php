<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/DataBlueprint.php';

json_response([
    'ok' => true,
    'sources' => DataBlueprint::sources(),
    'datasets' => DataBlueprint::targetDatasets(),
    'scoring_layers' => DataBlueprint::scoringLayers(),
]);
