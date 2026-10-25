<?php

use App\Debug\DebugCollector;

if (!function_exists('dump')) {
    function dump(mixed $value, ?string $label = null): void
    {
        DebugCollector::addDump($value, $label);
    }
}
