<?php

namespace Techful\Idempotency\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Techful\Idempotency\IdempotencyServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            IdempotencyServiceProvider::class,
        ];
    }
}
