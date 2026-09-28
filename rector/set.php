<?php

declare(strict_types=1);

// Rector set for moving applications to noirapi/database 5 helpers. In the app's rector.php:
//   return RectorConfig::configure()->withPaths([__DIR__ . '/app'])
//       ->withSets([__DIR__ . '/vendor/noirapi/database/rector/set.php']);

use Noirapi\Database\Rector\BitMaskWhereRector;
use Noirapi\Database\Rector\RawFunctionCallRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()->withRules([
    BitMaskWhereRector::class,
    RawFunctionCallRector::class,
]);
