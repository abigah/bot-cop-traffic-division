<?php

namespace Abigah\BotCopTrafficDivision\Models;

use Abigah\BotCopTrafficDivision\Concerns\IsMonitoredSite;
use Illuminate\Database\Eloquent\Model;

/**
 * The package's own site model, for hosts with no site concept of their own.
 *
 * It is the default `monitoring.site_model` so a single-model install works
 * untouched, exactly the way `owner_model` defaults to User. A host that has
 * its own site model points the config at that instead, adds the
 * IsMonitoredSite trait to it, and this class is never instantiated.
 */
class MonitoredSite extends Model
{
    use IsMonitoredSite;

    protected $table = 'monitor_sites';

    protected $guarded = [];
}
