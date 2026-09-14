#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$config = require dirname(__DIR__) . '/config/config.php';
$container = (require dirname(__DIR__) . '/config/container.php')($config);

$result = $container->get(App\Application\LandingSyncService::class)->run();
printf("Sync #%d: %d added, %d updated, %d removed (%d ms)\n", $result['id'], $result['added'], $result['updated'], $result['removed'], $result['duration_ms']);
