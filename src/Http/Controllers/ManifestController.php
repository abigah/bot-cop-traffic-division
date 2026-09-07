<?php

namespace Abigah\BotCopTrafficDivision\Http\Controllers;

use Abigah\BotCopTrafficDivision\Support\ManifestBuilder;
use Illuminate\Http\JsonResponse;

/**
 * `GET /monitoring/manifest`
 *
 * Pulled on boot, on a slow reconcile schedule, and after a change notice. It
 * doubles as the prober's "can I reach this extranet" heartbeat, which is why
 * it is the one endpoint that must answer even when everything else is quiet.
 */
class ManifestController
{
    public function __invoke(ManifestBuilder $manifest): JsonResponse
    {
        return response()->json($manifest->build());
    }
}
