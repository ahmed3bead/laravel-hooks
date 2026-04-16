<?php

namespace Ahmed3bead\LaravelHooks\Tests;

use Ahmed3bead\LaravelHooks\LaravelHooksServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelHooksServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('laravel-hooks.enabled', true);
        $app['config']->set('laravel-hooks.debug', false);
        $app['config']->set('queue.default', 'sync');
    }
}
