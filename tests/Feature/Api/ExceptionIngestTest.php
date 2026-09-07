<?php

use Abigah\BotCopTrafficDivision\Events\SiteExceptionReported;
use Abigah\BotCopTrafficDivision\Models\MonitoredSite;
use Abigah\BotCopTrafficDivision\Models\MonitorSiteException;
use Abigah\BotCopTrafficDivision\Tests\Support\SignsRequests;
use Illuminate\Support\Facades\Event;

uses(SignsRequests::class);

beforeEach(function () {
    config()->set('monitoring.tenant', 'acme');

    $this->site = MonitoredSite::create(['name' => 'Site', 'owner_id' => 1]);
});

function exceptionDelivery(array $exceptions): array
{
    return [
        'schema' => 1,
        'prober' => ['id' => 'cf-prober-1', 'location' => 'cloudflare'],
        'exceptions' => $exceptions,
        'suppressed' => [],
        'dropped' => [],
    ];
}

function fingerprint(string $seed = 'a'): string
{
    return str_repeat($seed, 64);
}

function reported(int|string $siteId, string $fingerprint, array $extra = []): array
{
    return [
        'site_id' => (string) $siteId,
        'fingerprint' => $fingerprint,
        'class' => 'Illuminate\\Database\\QueryException',
        'message' => 'SQLSTATE[HY000] [2002] Connection refused',
        'file' => 'app/Http/Controllers/OrderController.php',
        'line' => 84,
        'first_seen' => now()->subMinutes(5)->toIso8601ZuluString(),
        'last_seen' => now()->toIso8601ZuluString(),
        'count' => 1,
        'is_new' => true,
        'trace' => null,
        ...$extra,
    ];
}

it('records a new fingerprint and tells someone', function () {
    Event::fake([SiteExceptionReported::class]);

    $this->signedPost('monitoring/exceptions', exceptionDelivery([
        reported($this->site->id, fingerprint()),
    ]))->assertOk()->assertJson(['recorded' => 1]);

    Event::assertDispatched(SiteExceptionReported::class, 1);

    $exception = MonitorSiteException::first();

    expect($exception->exception_class)->toBe('Illuminate\\Database\\QueryException')
        ->and($exception->occurrences)->toBe(1)
        ->and($exception->notified_at)->not->toBeNull();
});

/**
 * A fingerprint that keeps firing is a number going up, not a page per
 * occurrence. That is the whole difference between this and an error tracker.
 */
it('counts a repeat without telling anyone again', function () {
    Event::fake([SiteExceptionReported::class]);

    $this->signedPost('monitoring/exceptions', exceptionDelivery([
        reported($this->site->id, fingerprint()),
    ]))->assertOk();

    $this->signedPost('monitoring/exceptions', exceptionDelivery([
        reported($this->site->id, fingerprint(), ['count' => 37, 'is_new' => false]),
    ]))->assertOk();

    Event::assertDispatched(SiteExceptionReported::class, 1);

    expect(MonitorSiteException::count())->toBe(1)
        ->and(MonitorSiteException::first()->occurrences)->toBe(37);
});

/**
 * Resolving a fingerprint is what makes a recurrence after a fix reach somebody
 * rather than disappearing into a count that has been climbing for a month.
 */
it('treats a recurrence after a resolve as new again', function () {
    Event::fake([SiteExceptionReported::class]);

    $this->signedPost('monitoring/exceptions', exceptionDelivery([
        reported($this->site->id, fingerprint()),
    ]))->assertOk();

    MonitorSiteException::first()->resolve();

    $this->signedPost('monitoring/exceptions', exceptionDelivery([
        reported($this->site->id, fingerprint(), ['count' => 1]),
    ]))->assertOk();

    Event::assertDispatched(SiteExceptionReported::class, 2);

    expect(MonitorSiteException::count())->toBe(1)
        ->and(MonitorSiteException::first()->resolved_at)->toBeNull();
});

it('keeps the same fingerprint on two sites apart', function () {
    $other = MonitoredSite::create(['name' => 'Other', 'owner_id' => 1]);

    $this->signedPost('monitoring/exceptions', exceptionDelivery([
        reported($this->site->id, fingerprint()),
        reported($other->id, fingerprint()),
    ]))->assertOk();

    expect(MonitorSiteException::count())->toBe(2);
});

it('truncates a long message rather than storing it whole', function () {
    config()->set('monitoring.exceptions.max_message_length', 50);

    $this->signedPost('monitoring/exceptions', exceptionDelivery([
        reported($this->site->id, fingerprint(), ['message' => str_repeat('x', 500)]),
    ]))->assertOk();

    expect(strlen(MonitorSiteException::first()->message))->toBeLessThanOrEqual(53);
});

it('drops the trace when this application does not want traces', function () {
    config()->set('monitoring.exceptions.store_traces', false);

    $this->signedPost('monitoring/exceptions', exceptionDelivery([
        reported($this->site->id, fingerprint(), ['trace' => "#0 app/Foo.php(1)\n#1 app/Bar.php(2)"]),
    ]))->assertOk();

    expect(MonitorSiteException::first()->trace)->toBeNull();
});
