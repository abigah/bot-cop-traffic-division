<?php

use Abigah\BotCopTrafficDivision\Http\Middleware\VerifyProberSignature;
use Abigah\BotCopTrafficDivision\Models\Monitor;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Abigah\BotCopTrafficDivision\Models\MonitorIncident;
use Abigah\BotCopTrafficDivision\Tests\Fixtures\User;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

/**
 * The screens are behind whatever the host authenticates with, and three other
 * groups deliberately are not. Getting that wrong is not a visible failure: it
 * is a monitoring dashboard anyone can read, or a prober whose deliveries start
 * failing CSRF.
 */
function middlewareFor(string $name): array
{
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($route) => $route->getName() === $name);

    expect($route)->not->toBeNull("Route [{$name}] is not registered.");

    return $route->gatherMiddleware();
}

/**
 * Route model binding is appended rather than assumed: a host's own stack has
 * no reason to include it, and {site} and {monitor} need it.
 */
it('keeps route model binding on the screens whatever the host configures', function () {
    expect(middlewareFor('monitoring.site'))->toContain(SubstituteBindings::class)
        ->and(middlewareFor('monitoring.monitor.history'))->toContain(SubstituteBindings::class);
});

it('applies the configured middleware rather than ignoring it', function () {
    // The harness boots with the package default, which is the thing under test.
    $screen = middlewareFor('monitoring.dashboard');

    expect($screen)->toContain('web')
        ->and($screen)->toContain('auth');
});

/**
 * Someone woken at 3am should not have to log in to make the phone stop. Route
 * middleware adds to a group's rather than replacing it, so this route being in
 * the screens' group would silently give it their auth stack.
 */
it('leaves the mute link signed and unauthenticated', function () {
    $mute = middlewareFor('monitoring.incident.mute');

    expect($mute)->toContain('signed')
        ->and($mute)->not->toContain('auth')
        ->and($mute)->not->toContain('auth:sanctum');
});

it('reaches the mute link with a valid signature and nobody logged in', function () {
    $recipient = User::create(['name' => 'On call', 'email' => 'oncall@test.dev']);
    $site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);
    $monitor = $site->monitors()->create(['url' => 'https://site.test/', 'owner_id' => 1]);

    $incident = MonitorIncident::create([
        'monitor_id' => $monitor->id,
        'site_id' => $site->id,
        'started_at' => now(),
    ]);

    $this->assertGuest();

    $this->get(URL::signedRoute('monitoring.incident.mute', [
        'incident' => $incident->id,
        'notifiable' => $recipient->id,
    ]))->assertOk()->assertSee('Notifications muted');

    expect($incident->fresh()->isMutedBy($recipient))->toBeTrue();
});

/**
 * A prober has no session and no CSRF token. The web stack in front of these
 * would fail every delivery.
 */
it('keeps the web stack away from the prober API', function () {
    config()->set('monitoring.middleware', ['web', 'auth']);

    foreach (['monitoring.manifest', 'monitoring.results', 'monitoring.heartbeats', 'monitoring.exceptions'] as $name) {
        $api = middlewareFor($name);

        expect($api)->toContain(VerifyProberSignature::class)
            ->and($api)->not->toContain('web')
            ->and($api)->not->toContain('auth');
    }
});

/**
 * Called by a queue worker or a deploy script, which hold no secret and cannot
 * carry a session. A token in the path and a throttle is the whole of it.
 */
it('keeps the web stack away from the token endpoints', function () {
    foreach (['monitoring.ping', 'monitoring.report'] as $name) {
        $ingest = middlewareFor($name);

        expect($ingest)->not->toContain('web')
            ->and($ingest)->not->toContain('auth')
            ->and(collect($ingest)->contains(fn ($m) => str_starts_with((string) $m, 'throttle:')))->toBeTrue();
    }
});

it('keeps the deployment links signed and unauthenticated', function () {
    $deploy = middlewareFor('monitoring.deployment.start');

    expect($deploy)->toContain('signed')
        ->and($deploy)->not->toContain('auth');
});

it('registers nothing at all when the host says not to', function () {
    expect(config('monitoring.register_routes'))->toBeTrue();

    // Guard against the flag being ignored the way the middleware was.
    expect(Route::getRoutes()->getRoutes())->not->toBeEmpty();
});
