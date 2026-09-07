<?php

use Illuminate\Support\Facades\File;

/*
| The command writes to .env, so every test here works against a throwaway one
| and points the application at it. A test that edited the real file would be a
| test that could destroy a developer's environment.
*/
beforeEach(function () {
    $this->envPath = sys_get_temp_dir().'/bot-cop-env-'.Str::random(8);
    File::put($this->envPath, "APP_NAME=Testing\n");

    app()->useEnvironmentPath(dirname($this->envPath));
    app()->loadEnvironmentFrom(basename($this->envPath));

    config()->set('monitoring.tenant', 'acme');
    config()->set('monitoring.probers', [
        'cf-prober-1' => ['base_url' => 'https://prober.test', 'secrets' => []],
    ]);
});

afterEach(function () {
    File::delete($this->envPath);
});

it('writes a secret to .env', function () {
    $this->artisan('monitoring:prober:secret')->assertExitCode(0);

    $contents = File::get($this->envPath);

    expect($contents)->toContain('MONITORING_PROBER_SECRET="');

    preg_match('/^MONITORING_PROBER_SECRET="(.*)"$/m', $contents, $m);

    // 32 random bytes, base64'd. strlen, not toHaveLength: the decoded value is
    // binary, and counting it as characters is not counting bytes.
    expect(strlen(base64_decode($m[1], strict: true)))->toBe(32);
});

it('generates a different secret every time', function () {
    $secrets = collect(range(1, 3))->map(function () {
        File::put($this->envPath, "APP_NAME=Testing\n");
        $this->artisan('monitoring:prober:secret')->run();
        preg_match('/^MONITORING_PROBER_SECRET="(.*)"$/m', File::get($this->envPath), $m);

        return $m[1];
    });

    expect($secrets->unique())->toHaveCount(3);
});

it('quotes the value, because a base64 secret can end in =', function () {
    $this->artisan('monitoring:prober:secret')->run();

    expect(File::get($this->envPath))->toMatch('/^MONITORING_PROBER_SECRET=".*"$/m');
});

it('refuses to overwrite an existing secret', function () {
    File::put($this->envPath, "MONITORING_PROBER_SECRET=\"already-here\"\n");

    $this->artisan('monitoring:prober:secret')->assertExitCode(1);

    // The point of the guard: silently replacing this breaks a working link.
    expect(File::get($this->envPath))->toContain('already-here');
});

it('rotates the current secret into the previous slot', function () {
    File::put($this->envPath, "MONITORING_PROBER_SECRET=\"the-old-one\"\n");

    $this->artisan('monitoring:prober:secret --rotate')->assertExitCode(0);

    $contents = File::get($this->envPath);

    expect($contents)->toContain('MONITORING_PROBER_SECRET_PREVIOUS="the-old-one"')
        ->and($contents)->not->toContain('MONITORING_PROBER_SECRET="the-old-one"');
});

it('will not rotate when there is nothing to rotate', function () {
    $this->artisan('monitoring:prober:secret --rotate')->assertExitCode(1);

    expect(File::get($this->envPath))->not->toContain('MONITORING_PROBER_SECRET');
});

it('prints without writing when asked to show', function () {
    $this->artisan('monitoring:prober:secret --show')->assertExitCode(0);

    expect(File::get($this->envPath))->not->toContain('MONITORING_PROBER_SECRET');
});

it('names the prober-side env var from the tenant', function () {
    $this->artisan('monitoring:prober:secret')
        ->expectsOutputToContain('wrangler secret put PROBER_ACME_SECRET')
        ->assertExitCode(0);
});

it('warns when the tenant is still the default', function () {
    config()->set('monitoring.tenant', 'default');

    $this->artisan('monitoring:prober:secret')
        ->expectsOutputToContain('MONITORING_TENANT')
        ->assertExitCode(0);
});

it('refuses an unknown prober id', function () {
    $this->artisan('monitoring:prober:secret nonexistent')->assertExitCode(1);

    expect(File::get($this->envPath))->not->toContain('MONITORING_PROBER_SECRET');
});

it('requires a prober to be named when several are configured', function () {
    config()->set('monitoring.probers', [
        'cf-prober-1' => ['base_url' => 'https://one.test', 'secrets' => []],
        'cf-prober-2' => ['base_url' => 'https://two.test', 'secrets' => []],
    ]);

    // A secret is per prober per tenant; writing the wrong one into .env shows
    // up much later as an unexplained signature failure.
    $this->artisan('monitoring:prober:secret')->assertExitCode(1);

    $this->artisan('monitoring:prober:secret cf-prober-2')->assertExitCode(0);
});
