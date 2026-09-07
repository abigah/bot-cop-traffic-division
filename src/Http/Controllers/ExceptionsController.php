<?php

namespace Abigah\BotCopTrafficDivision\Http\Controllers;

use Abigah\BotCopTrafficDivision\Events\SiteExceptionReported;
use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Models\MonitorSiteException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * `POST /monitoring/exceptions`
 *
 * Server errors a site reported about itself, folded by fingerprint.
 *
 * The prober decides only whether a report is worth forwarding — during an
 * outage a 500 storm is the outage, so those are folded into the incident
 * instead. What arrives here is judged the way everything else is: a
 * fingerprint nobody has seen notifies once, and one that keeps firing becomes
 * a number going up.
 */
class ExceptionsController
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'schema' => ['required', 'integer'],
            'prober.id' => ['required', 'string'],
            'prober.location' => ['required', 'string'],
            'exceptions' => ['present', 'array'],
            'exceptions.*.site_id' => ['required'],
            'exceptions.*.fingerprint' => ['required', 'string', 'size:64'],
            'exceptions.*.class' => ['required', 'string'],
            'exceptions.*.message' => ['nullable', 'string'],
            'exceptions.*.file' => ['nullable', 'string'],
            'exceptions.*.line' => ['nullable', 'integer'],
            'exceptions.*.first_seen' => ['required', 'date'],
            'exceptions.*.last_seen' => ['required', 'date'],
            'exceptions.*.count' => ['required', 'integer'],
            'exceptions.*.is_new' => ['sometimes', 'boolean'],
            'exceptions.*.trace' => ['nullable', 'string'],
        ]);

        $recorded = 0;

        foreach ($payload['exceptions'] as $row) {
            $this->record($row);
            $recorded++;
        }

        return response()->json([
            'schema' => Monitoring::schemaVersion(),
            'recorded' => $recorded,
        ]);
    }

    /** @param  array<string, mixed>  $row */
    protected function record(array $row): void
    {
        $existing = MonitorSiteException::query()
            ->where('site_id', $row['site_id'])
            ->where('fingerprint', $row['fingerprint'])
            ->first();

        /*
         | New means new to this application, not new to the reporter. A
         | fingerprint that was resolved and has come back is new again — that
         | is the point of resolving one: a recurrence after a fix should
         | reach somebody rather than disappear into a count that has been
         | climbing for a month.
         */
        $isNew = $existing === null || $existing->isResolved();

        $attributes = [
            'exception_class' => $row['class'],
            'message' => $this->truncate($row['message'] ?? null),
            'file' => $row['file'] ?? null,
            'line' => $row['line'] ?? null,
            'trace' => config('monitoring.exceptions.store_traces', true) ? ($row['trace'] ?? null) : null,
            'last_seen_at' => Carbon::parse($row['last_seen']),
        ];

        if ($isNew) {
            $exception = MonitorSiteException::updateOrCreate(
                ['site_id' => $row['site_id'], 'fingerprint' => $row['fingerprint']],
                [
                    ...$attributes,
                    'first_seen_at' => Carbon::parse($row['first_seen']),
                    'occurrences' => (int) $row['count'],
                    'resolved_at' => null,
                    'notified_at' => Carbon::now(),
                ],
            );

            event(new SiteExceptionReported($exception));

            return;
        }

        // A count from the reporter is the total it has seen, not a delta.
        $existing->forceFill([
            ...$attributes,
            'occurrences' => max($existing->occurrences, (int) $row['count']),
        ])->save();
    }

    protected function truncate(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        return Str::limit($message, (int) config('monitoring.exceptions.max_message_length', 1000));
    }
}
