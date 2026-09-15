<?php

namespace Abigah\BotCopTrafficDivision\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * One monitoring event, described for a push notification.
 *
 * This is all a push delivery channel needs and nothing it should not have: a
 * concise title and body, whose news it is, where tapping it should lead, the
 * outage it belongs to when there is one, and whether it is urgent enough to
 * break through a recipient's focus. It carries no trace, request data or
 * secret, and it knows nothing about any particular delivery provider — the
 * host's channel turns it into whatever its provider expects.
 *
 * `owner_id` is this package's owner key, which the host maps to its own
 * concept of whose news it is (a team, say).
 *
 * A title is at most TITLE_MAX_LENGTH long and a body at most BODY_MAX_LENGTH,
 * both counted in Unicode code points — not bytes, and not characters as a
 * person sees them. The package's own notifications never exceed them. This
 * class does not enforce them, though, so a message built any other way can:
 * a delivery channel checks for itself before handing one to its provider.
 */
final readonly class PushMessage
{
    /**
     * The longest title a push carries, in Unicode code points.
     */
    public const TITLE_MAX_LENGTH = 64;

    /**
     * The longest body a push carries, in Unicode code points.
     */
    public const BODY_MAX_LENGTH = 178;

    public const DESTINATION_MONITOR = 'monitor';

    public const DESTINATION_HEARTBEAT = 'heartbeat';

    public const DESTINATION_EXCEPTION = 'exception';

    /**
     * The places a consumer knows how to open.
     *
     * @var array<int, string>
     */
    public const DESTINATION_TYPES = [
        self::DESTINATION_MONITOR,
        self::DESTINATION_HEARTBEAT,
        self::DESTINATION_EXCEPTION,
    ];

    public function __construct(
        public string $eventType,
        public string $title,
        public string $body,
        public int|string $ownerId,
        public string $destinationType,
        public int|string $destinationId,
        public ?int $incidentId,
        public CarbonImmutable $occurredAt,
        public bool $timeSensitive,
    ) {
        if (! in_array($destinationType, self::DESTINATION_TYPES, true)) {
            throw new InvalidArgumentException(sprintf(
                'A push message cannot lead to "%s"; its destination must be one of: %s.',
                $destinationType,
                implode(', ', self::DESTINATION_TYPES),
            ));
        }
    }

    /**
     * The moment is always spelled in UTC with a trailing Z and whole seconds,
     * whatever timezone it was recorded in.
     *
     * @return array<string, bool|int|string|null>
     */
    public function toArray(): array
    {
        return [
            'event_type' => $this->eventType,
            'title' => $this->title,
            'body' => $this->body,
            'owner_id' => $this->ownerId,
            'destination_type' => $this->destinationType,
            'destination_id' => $this->destinationId,
            'incident_id' => $this->incidentId,
            'occurred_at' => $this->occurredAt->utc()->format('Y-m-d\TH:i:s\Z'),
            'time_sensitive' => $this->timeSensitive,
        ];
    }
}
