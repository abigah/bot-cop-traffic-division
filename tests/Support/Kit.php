<?php

namespace Abigah\BotCopTrafficDivision\Tests\Support;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use RuntimeException;

/**
 * The conformance kit, read from the pinned `bot-cop-traffic-contracts`
 * submodule.
 *
 * The kit is the specification: schemas, example payloads, must-reject cases,
 * HMAC vectors and rule fixtures. Tests here consume those files rather than
 * restating what they say, so a contract change lands as a submodule bump and
 * whatever it breaks breaks loudly.
 */
class Kit
{
    public const VERSION = 1;

    public static function path(string $relative = ''): string
    {
        $base = __DIR__.'/../contracts/v'.self::VERSION;

        if (! is_dir($base)) {
            throw new RuntimeException(
                'The contracts submodule is missing. Run `composer contracts:init`.'
            );
        }

        return $relative === '' ? $base : $base.'/'.ltrim($relative, '/');
    }

    /** @return array<string, mixed> */
    public static function json(string $relative): array
    {
        $contents = file_get_contents(self::path($relative));

        if ($contents === false) {
            throw new RuntimeException("Unable to read {$relative} from the conformance kit.");
        }

        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }

    /** The raw bytes of a kit file, for anything that must be signed as sent. */
    public static function raw(string $relative): string
    {
        return (string) file_get_contents(self::path($relative));
    }

    /** @return array<string, mixed> */
    public static function example(string $name): array
    {
        return self::json("examples/{$name}.json");
    }

    /** @return array<string, mixed> */
    public static function fixture(string $name): array
    {
        return self::json("fixtures/{$name}.json");
    }

    /** @return array<string, mixed> */
    public static function vectors(): array
    {
        return self::json('hmac/vectors.json');
    }

    /**
     * The payloads a conforming implementation must refuse, each naming the one
     * rule it isolates.
     *
     * @return array<int, array{name: string, schema: string, because: string, payload: array<string, mixed>}>
     */
    public static function invalidCases(): array
    {
        return self::json('examples-invalid/cases.json')['cases'];
    }

    /** @return array<int, string> Schema names, e.g. `manifest`. */
    public static function schemaNames(): array
    {
        return array_map(
            fn (string $file): string => basename($file, '.schema.json'),
            glob(self::path('schema/*.schema.json')) ?: []
        );
    }

    /**
     * Validate a payload against one of the kit's schemas, returning the
     * validation errors as readable paths. An empty array means it conforms.
     *
     * Prefer handing this the raw JSON bytes. A payload that has been through a
     * PHP associative array cannot tell an empty object from an empty list, and
     * `headers: {}` becoming `headers: []` is a schema failure the wire would
     * have had too — so the round trip hides nothing, it just moves where the
     * mistake shows up.
     *
     * @param  string|array<string, mixed>|object  $payload
     * @return array<string, array<int, string>>
     */
    public static function validate(string $schemaName, string|array|object $payload): array
    {
        $validator = new Validator;
        $validator->resolver()->registerFile(
            self::schemaId($schemaName),
            self::path("schema/{$schemaName}.schema.json")
        );

        $result = $validator->validate(
            is_string($payload)
                ? json_decode($payload, false, 512, JSON_THROW_ON_ERROR)
                : json_decode(json_encode($payload, JSON_THROW_ON_ERROR), false),
            self::schemaId($schemaName)
        );

        if ($result->isValid()) {
            return [];
        }

        return (new ErrorFormatter)->format($result->error());
    }

    protected static function schemaId(string $schemaName): string
    {
        $id = self::json("schema/{$schemaName}.schema.json")['$id'] ?? null;

        return is_string($id) ? $id : 'https://bot-cop.local/v'.self::VERSION."/{$schemaName}";
    }
}
