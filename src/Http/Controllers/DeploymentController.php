<?php

namespace Abigah\BotCopTrafficDivision\Http\Controllers;

use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Models\Monitor;
use Illuminate\Http\JsonResponse;

/**
 * The signed URLs a deploy script calls. A deploy still only ever talks to this
 * application; whether a prober exists changes nothing about the CI step.
 */
class DeploymentController
{
    public function startSite(string $site): JsonResponse
    {
        $model = Monitoring::siteModel()::findOrFail($site);
        $model->startDeployment();

        return response()->json(['status' => 'started']);
    }

    public function finishSite(string $site): JsonResponse
    {
        $model = Monitoring::siteModel()::findOrFail($site);
        $model->finishDeployment();

        return response()->json(['status' => 'finished']);
    }

    /** The older per-monitor pair, kept working while deploy scripts move over. */
    public function startMonitor(Monitor $monitor): JsonResponse
    {
        $monitor->deployments()->create(['started_at' => now()]);

        return response()->json(['status' => 'started']);
    }

    public function finishMonitor(Monitor $monitor): JsonResponse
    {
        $monitor->deployments()
            ->ongoing()
            ->latest('started_at')
            ->first()
            ?->update(['finished_at' => now()]);

        return response()->json(['status' => 'finished']);
    }
}
