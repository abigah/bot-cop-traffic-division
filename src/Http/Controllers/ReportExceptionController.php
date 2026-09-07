<?php

namespace Abigah\BotCopTrafficDivision\Http\Controllers;

use Abigah\BotCopTrafficDivision\Events\SiteExceptionReported;
use Abigah\BotCopTrafficDivision\Models\MonitorSiteException;
use Abigah\BotCopTrafficDivision\Models\MonitorSiteSetting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * `POST /monitoring/report/{token}`
 *
 * Where a site says it threw a server error.
 *
 * Token-authenticated and throttled rather than signed, for the same reason
 * pings are, and the local-mode counterpart of the prober's own report
 * endpoint.
 *
 * The site rule applies here too: a 500 storm while the site is down is the
 * outage, so the fingerprints are recorded and nobody is paged for them. It is
 * only news when the site is up.
 */
class ReportExceptionController
{
    public function __invoke(Request $request, string $token): JsonResponse
    {
        $site = $this->siteFor($token);

        if ($site === null) {
            return response()->json(['status' => 'ignored'], 404);
        }

        $payload = $request->validate([
            'schema' => ['required', 'integer'],
            'fingerprints' => ['present', 'array'],
            'fingerprints.*.fingerprint' => ['required', 'string', 'size:64'],
            'fingerprints.*.class' => ['required', 'string'],
            'fingerprints.*.message' => ['nullable', 'string'],
            'fingerprints.*.file' => ['nullable', 'string'],
            'fingerprints.*.line' => ['nullable', 'integer'],
            'fingerprints.*.first_seen' => ['required', 'date'],
            'fingerprints.*.last_seen' => ['required', 'date'],
            'fingerprints.*.count' => ['required', 'integer'],
            'fingerprints.*.trace' => ['nullable', 'string'],
        ]);

        $siteIsUp = $site->criticalStatus() !== 'down';

        foreach ($payload['fingerprints'] as $row) {
            $this->record($site, $row, $siteIsUp);
        }

        return response()->json(['status' => 'ok'], 202);
    }

    protected function siteFor(string $token): ?Model
    {
        $siteId = MonitorSiteSetting::query()->where('ingest_token', $token)->value('site_id');

        return $siteId === null ? null : config('monitoring.site_model')::find($siteId);
    }

    /** @param  array<string, mixed>  $row */
    protected function record(Model $site, array $row, bool $siteIsUp): void
    {
        $existing = MonitorSiteException::query()
            ->where('site_id', $site->getKey())
            ->where('fingerprint', $row['fingerprint'])
            ->first();

        $isNew = $existing === null || $existing->isResolved();

        $attributes = [
            'exception_class' => $row['class'],
            'message' => $this->truncate($row['message'] ?? null),
            'file' => $row['file'] ?? null,
            'line' => $row['line'] ?? null,
            'trace' => config('monitoring.exceptions.store_traces', true) ? ($row['trace'] ?? null) : null,
            'last_seen_at' => Carbon::parse($row['last_seen']),
        ];

        if (! $isNew) {
            $existing->forceFill([
                ...$attributes,
                'occurrences' => $existing->occurrences + (int) $row['count'],
            ])->save();

            return;
        }

        $exception = MonitorSiteException::updateOrCreate(
            ['site_id' => $site->getKey(), 'fingerprint' => $row['fingerprint']],
            [
                ...$attributes,
                'first_seen_at' => Carbon::parse($row['first_seen']),
                'occurrences' => (int) $row['count'],
                'resolved_at' => null,
                'notified_at' => $siteIsUp ? Carbon::now() : null,
            ],
        );

        // While the site is down, a storm of 500s is the outage. Recorded, not
        // paged for.
        if ($siteIsUp) {
            event(new SiteExceptionReported($exception));
        }
    }

    protected function truncate(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        return Str::limit($message, (int) config('monitoring.exceptions.max_message_length', 1000));
    }
}
