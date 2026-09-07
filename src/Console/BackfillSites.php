<?php

namespace Abigah\BotCopTrafficDivision\Console;

use Abigah\BotCopTrafficDivision\Models\Monitor;
use Abigah\BotCopTrafficDivision\Support\SiteMatcher;
use Illuminate\Console\Command;

/**
 * Attaches monitors to sites.
 *
 * An install that predates sites has monitors and nothing to group them under,
 * and a monitor with no site is invisible to a prober: the manifest is a list
 * of sites, so a monitor that belongs to none of them is never checked.
 *
 * Safe to run repeatedly. It only ever touches monitors that have no site.
 */
class BackfillSites extends Command
{
    protected $signature = 'monitoring:sites:backfill
        {--dry-run : Show what would be attached without writing anything}
        {--critical : Mark every attached monitor critical (the default for a fresh monitor)}';

    protected $description = 'Group monitors that have no site into sites, by host';

    public function handle(SiteMatcher $matcher): int
    {
        $matcher->guardResolverIsWired();

        $orphans = Monitor::query()->whereNull('site_id')->orderBy('url')->get();

        if ($orphans->isEmpty()) {
            $this->info('Every monitor already belongs to a site.');

            return self::SUCCESS;
        }

        $dryRun = $this->option('dry-run');
        $attached = 0;
        $skipped = [];

        foreach ($orphans as $monitor) {
            $site = $matcher->for($monitor, create: ! $dryRun);

            if ($site === null) {
                $skipped[] = (string) $monitor->url;

                continue;
            }

            $this->line(sprintf('  %-55s → %s', $monitor->url, $site->name ?? $site->getKey()));

            if (! $dryRun) {
                $monitor->forceFill([
                    'site_id' => $site->getKey(),
                    'critical' => $this->option('critical') ? true : $monitor->critical,
                ])->save();
            }

            $attached++;
        }

        $this->newLine();
        $this->info($dryRun
            ? "Would attach {$attached} ".str('monitor')->plural($attached).'.'
            : "Attached {$attached} ".str('monitor')->plural($attached).'.');

        if ($skipped !== []) {
            $this->newLine();
            $this->warn(count($skipped).' left unattached — the site resolver returned nothing for them:');

            foreach ($skipped as $url) {
                $this->line("  {$url}");
            }
        }

        return self::SUCCESS;
    }
}
