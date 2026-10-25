<?php

use App\Bootstrap;

require dirname(__DIR__) . '/src/debug_helpers.php';
require dirname(__DIR__) . '/vendor/autoload.php';

$bootstrap = new Bootstrap();
$response = $bootstrap->handle();
$response->send();
