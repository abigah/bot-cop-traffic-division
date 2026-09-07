<?php

namespace Abigah\BotCopTrafficDivision\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Mints the secret this application and a prober sign with.
 *
 * There is nothing to register with and nobody to ask: the secret is symmetric
 * and the two ends simply have to hold the same bytes. Both sides read it from
 * their own platform's environment — `MONITORING_PROBER_SECRET` here, a Wrangler
 * secret on the Worker — and neither ever holds the other's copy.
 *
 * That symmetry is why this exists. Written by hand it becomes a passphrase
 * somebody could guess, or it gets pasted into a config file and committed, or
 * the two halves quietly differ by a trailing newline and every signature fails
 * with an error that says only "signature". Generating it removes all three.
 *
 * Rotation is why both sides take a *list* of secrets, current first: a verifier
 * accepts any of them, so a new secret can be in place on one end while the old
 * one is still in use on the other. --rotate does that shuffle here; the gap
 * closes when the prober has the new value too.
 */
class GenerateProberSecret extends Command
{
    protected $signature = 'monitoring:prober:secret
        {prober? : The prober id from config(\'monitoring.probers\'); defaults to the only one}
        {--rotate : Keep the current secret as the previous one, so signing does not break mid-change}
        {--show : Print the secret without writing to .env}';

    protected $description = 'Generate the shared secret this application and a prober sign with';

    /** The current secret's env var, and the slot a rotation moves it into. */
    private const CURRENT = 'MONITORING_PROBER_SECRET';

    private const PREVIOUS = 'MONITORING_PROBER_SECRET_PREVIOUS';

    public function handle(): int
    {
        $prober = $this->prober();

        if ($prober === null) {
            return self::FAILURE;
        }

        // 32 bytes from the CSPRNG, base64'd. Long enough that guessing is not a
        // strategy, and printable so it survives a .env file, a Wrangler prompt
        // and whatever sits between them.
        $secret = base64_encode(random_bytes(32));

        if ($this->option('show')) {
            $this->line($secret);

            return self::SUCCESS;
        }

        if (! $this->write($secret)) {
            return self::FAILURE;
        }

        $this->instruct($prober, $secret);

        return self::SUCCESS;
    }

    /**
     * Which prober this secret is for.
     *
     * Named explicitly when there is more than one, because a secret is per
     * prober per tenant and writing the wrong one into .env is a failure that
     * shows up later as an unexplained signature error.
     */
    private function prober(): ?string
    {
        $probers = array_keys((array) config('monitoring.probers', []));
        $named = $this->argument('prober');

        if ($named !== null) {
            if (! in_array($named, $probers, strict: true)) {
                $this->components->error("No prober [{$named}] in config('monitoring.probers').");

                return null;
            }

            return $named;
        }

        if (count($probers) === 1) {
            return $probers[0];
        }

        if ($probers === []) {
            $this->components->error("No probers configured. Add one to config('monitoring.probers') first.");

            return null;
        }

        $this->components->error('Several probers are configured; name the one this secret is for: '.implode(', ', $probers));

        return null;
    }

    /**
     * Writes the secret into .env, the way key:generate does.
     *
     * Refuses rather than guesses when there is no .env or the key is absent:
     * appending a duplicate to a file that already sets it elsewhere produces a
     * value that depends on parse order, which is the kind of bug that is
     * invisible until it is a signature failure at 3am.
     */
    private function write(string $secret): bool
    {
        $path = $this->laravel->environmentFilePath();

        if (! is_file($path) || ! is_writable($path)) {
            $this->components->error("Cannot write {$path}. Use --show and set it yourself.");

            return false;
        }

        $contents = (string) file_get_contents($path);
        $current = $this->valueOf($contents, self::CURRENT);

        if ($this->option('rotate')) {
            if ($current === null) {
                $this->components->error('Nothing to rotate: '.self::CURRENT.' is not set. Run without --rotate.');

                return false;
            }

            $contents = $this->set($contents, self::PREVIOUS, $current);
        }

        if ($current !== null && ! $this->option('rotate')) {
            $this->components->error(
                self::CURRENT.' is already set. Use --rotate to replace it without a signing gap, or --show to print a value.'
            );

            return false;
        }

        $contents = $this->set($contents, self::CURRENT, $secret);

        file_put_contents($path, $contents);

        return true;
    }

    private function valueOf(string $contents, string $key): ?string
    {
        if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $contents, $matches) !== 1) {
            return null;
        }

        $value = trim($matches[1], " \t\"'");

        return $value === '' ? null : $value;
    }

    /** Replaces the key in place if present, appends it if not. */
    private function set(string $contents, string $key, string $value): string
    {
        // Quoted, because a base64 secret can end in '=' and an unquoted '='
        // is where some .env parsers stop reading.
        $line = $key.'="'.$value.'"';

        if (preg_match('/^'.preg_quote($key, '/').'=.*$/m', $contents) === 1) {
            return (string) preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $contents);
        }

        return rtrim($contents, "\n")."\n".$line."\n";
    }

    /**
     * The half this command cannot do.
     *
     * A secret set on one side only is worse than no secret: every delivery
     * fails a signature check rather than failing to be configured, and the
     * error says nothing about why. So the command ends by saying exactly what
     * to run on the other end.
     */
    private function instruct(string $prober, string $secret): void
    {
        $tenant = (string) config('monitoring.tenant', 'default');

        // Tenant slugs are [a-z0-9-]; the prober's env names are the same slug
        // uppercased, with dashes as underscores.
        $env = 'PROBER_'.Str::upper(str_replace('-', '_', $tenant)).'_SECRET';

        $this->newLine();
        $this->components->info(self::CURRENT.' written to .env for prober ['.$prober.'].');

        if ($tenant === 'default') {
            $this->components->warn(
                "config('monitoring.tenant') is still 'default'. Set MONITORING_TENANT to the key "
                ."this application has in the prober's tenant config, or the prober will not know "
                .'whose manifest this is.'
            );
        }

        $this->components->bulletList([
            'This is half of a shared secret. The prober needs the same value.',
            'On the prober: wrangler secret put '.$env,
            'Paste exactly this, with no trailing newline:',
        ]);

        $this->newLine();
        $this->line('  '.$secret);
        $this->newLine();

        if ($this->option('rotate')) {
            $this->components->warn(
                'Rotating: the old secret is kept as '.self::PREVIOUS.' and still verifies. '
                .'Remove it once the prober is on the new value.'
            );
        } else {
            $this->components->warn(
                'Until the prober has it, deliveries will fail their signature check. '
                .'That is the expected state in between, not a fault.'
            );
        }
    }
}
