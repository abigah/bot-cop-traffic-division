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

        /*
         | Pinned, all of it, because Testbench's skeleton carries a .env that
         | some of its own commands write on first run — `testbench list` is
         | enough — and it selects database-backed queue, cache and session
         | against a skeleton that has none of those tables. The suite then
         | fails for reasons that have nothing to do with the package, on
         | whichever machines happen to have run that command.
         |
         | A package's harness should not be able to be changed by something in
         | vendor/, so these say what they need rather than inheriting it.
         */
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('mail.default', 'array');

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
