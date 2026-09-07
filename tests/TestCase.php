<?php

namespace Abigah\BotCopTrafficDivision\Tests;

use Abigah\BotCopTrafficDivision\MonitoringServiceProvider;
use Abigah\BotCopTrafficDivision\Tests\Fixtures\User;
use Flux\FluxServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            // The package registers routes and components against Livewire, so
            // it has to be booted here the way it is in a host application.
            LivewireServiceProvider::class,

            /*
             | Free Flux only, deliberately, and Pro is not installed at all.
             | This package uses no Pro component; registering the Pro provider
             | here would let one back in without the suite noticing, which is
             | exactly how a commercial dependency reappears.
             */
            FluxServiceProvider::class,

            MonitoringServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');

        // Livewire signs its component snapshots, so the test app needs a key.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('monitoring.owner_model', User::class);
        $app['config']->set('monitoring.notifiable_model', User::class);
        $app['config']->set('monitoring.probers', [
            'cf-prober-1' => [
                'base_url' => 'https://prober.test',
                'secrets' => ['test-secret'],
            ],
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }
}
