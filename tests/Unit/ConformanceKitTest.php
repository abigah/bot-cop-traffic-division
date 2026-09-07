<?php

use Abigah\BotCopTrafficDivision\Tests\Support\Kit;

/**
 * The guard on the guard: it proves the pinned kit is present, readable and
 * self-consistent before any test relies on it. A failure here means the
 * submodule is missing or the pin moved to something broken, not that the hub
 * is wrong.
 */
it('has the pinned conformance kit available', function () {
    expect(Kit::path())->toBeDirectory()
        ->and(Kit::schemaNames())->not->toBeEmpty();
});

it('validates every kit example against its own schema', function (string $schemaName) {
    $example = Kit::path("examples/{$schemaName}.json");

    expect($example)->toBeReadableFile();

    expect(Kit::validate($schemaName, Kit::raw("examples/{$schemaName}.json")))->toBe([]);
})->with(fn () => Kit::schemaNames());

it('refuses every payload the kit says must be refused', function () {
    $cases = Kit::invalidCases();

    expect($cases)->not->toBeEmpty();

    foreach ($cases as $case) {
        expect(Kit::validate($case['schema'], json_encode($case['payload'], JSON_THROW_ON_ERROR)))
            ->not->toBe([], "Expected to refuse: {$case['name']}");
    }
});
