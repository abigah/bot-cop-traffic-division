<?php

namespace Abigah\BotCopTrafficDivision\Tests\Fixtures;

use Abigah\BotCopTrafficDivision\Concerns\IsMonitoredSite;
use Illuminate\Database\Eloquent\Model;

/**
 * Stands in for a host's own site model — one that belongs to a Client, or to
 * a Project. What matters for the tests is only
 * that it is not the package's own.
 */
class HostSite extends Model
{
    use IsMonitoredSite;

    protected $table = 'host_sites';

    protected $guarded = [];
}
