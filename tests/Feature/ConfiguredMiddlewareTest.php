<?php

namespace Abigah\BotCopTrafficDivision\Tests\Feature;

use Abigah\BotCopTrafficDivision\Tests\TestCase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

/**
 * Boots with a middleware stack the package has never heard of.
 *
 * Routes are registered while the application boots, so setting the config in a
 * test body proves nothing — the group has already been built. This sets it
 * first, which is the only way to show the configured value is read rather than
 * a hardcoded one that merely resembles it.
 *
 * A class rather than Pest's function style because the whole point is to
 * override `defineEnvironment`, and the Feature directory is already bound to
 * the base case.
 */
class ConfiguredMiddlewareTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('monitoring.middleware', ['web', 'auth:custom-guard', 'a-host-specific-middleware']);
        $app['config']->set('monitoring.route_prefix', 'ops');
    }

    /** @return array<int, string> */
    protected function middlewareFor(string $name): array
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(fn ($route) => $route->getName() === $name);

        $this->assertNotNull($route, "Route [{$name}] is not registered.");

        return $route->gatherMiddleware();
    }

    /**
     * The setting is documented, so it has to be read. It was not: the screens
     * were registered with route model binding alone, so a guest reaching the
     * dashboard got a 500 from the middle of a query scoped to an owner that
     * could not be resolved, rather than a redirect to sign in.
     */
    public function test_it_reads_the_hosts_middleware_rather_than_a_hardcoded_stack(): void
    {
        foreach ([
            'monitoring.dashboard',
            'monitoring.sites',
            'monitoring.site',
            'monitoring.monitor.history',
            'monitoring.incidents',
            'monitoring.preferences',
            'monitoring.probers',
        ] as $name) {
            $this->assertContains('auth:custom-guard', $this->middlewareFor($name), $name);
            $this->assertContains('a-host-specific-middleware', $this->middlewareFor($name), $name);
        }
    }

    /** The host's stack has no reason to include binding, and {site} needs it. */
    public function test_it_appends_route_model_binding_to_whatever_the_host_listed(): void
    {
        $this->assertContains(SubstituteBindings::class, $this->middlewareFor('monitoring.site'));
    }

    public function test_it_honours_the_route_prefix(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route) => $route->getName() === 'monitoring.dashboard');

        $this->assertSame('ops/monitoring', $route->uri());
    }

    /**
     * Route-level middleware adds to a group's rather than replacing it, so the
     * mute link lives in a group of its own. Inside the screens' group it would
     * silently inherit the auth stack it exists to avoid.
     */
    public function test_it_keeps_the_hosts_auth_away_from_the_mute_link(): void
    {
        $mute = $this->middlewareFor('monitoring.incident.mute');

        $this->assertContains('signed', $mute);
        $this->assertNotContains('auth:custom-guard', $mute);
        $this->assertNotContains('a-host-specific-middleware', $mute);
    }

    public function test_it_keeps_the_hosts_stack_away_from_the_prober_api(): void
    {
        $api = $this->middlewareFor('monitoring.results');

        $this->assertNotContains('a-host-specific-middleware', $api);
        $this->assertNotContains('web', $api);
    }
}
