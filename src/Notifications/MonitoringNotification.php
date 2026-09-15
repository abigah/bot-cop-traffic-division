<?php

namespace Abigah\BotCopTrafficDivision\Notifications;

use Abigah\BotCopTrafficDivision\Contracts\ProvidesPushMessage;
use Abigah\BotCopTrafficDivision\Facades\Monitoring;
use Abigah\BotCopTrafficDivision\Models\Monitor;
use Abigah\BotCopTrafficDivision\Models\MonitorHeartbeat;
use Abigah\BotCopTrafficDivision\Models\MonitorSiteException;
use Abigah\BotCopTrafficDivision\Support\PushMessage;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use LogicException;

/**
 * Everything this package sends, whatever it is about.
 *
 * The channels are decided before the notification is built — by the recipient's
 * own preferences and by whether they have muted the outage — so `via()` has
 * nothing left to work out.
 *
 * The subject is a monitor, a heartbeat or a reported exception. What they have
 * in common is that a person wants to be told, and that the same mute covers
 * all of them.
 *
 * Whose news it is, which outage it belongs to and whether it is urgent are
 * decided the same way, once for the event, and handed in: describing a push
 * never goes back to the database once per recipient, and never guesses an
 * owner from whoever happens to be signed in.
 */
abstract class MonitoringNotification extends Notification implements ProvidesPushMessage, ShouldQueue
{
    use Queueable;

    /**
     * The host of a URL PHP's own parser gave up on: after the scheme and any
     * credentials, up to the port, path, query or fragment. Credentials are
     * taken whole or not at all, so a URL with no host after them names
     * nothing rather than naming its credentials.
     */
    private const URL_HOST_PATTERN = '~^[a-z][a-z0-9+.\-]*://(?:[^@/?#]*@)?+([^@/\\\\?#:\[\]\s]+)(?=[:/\\\\?#]|\z)~i';

    /**
     * Characters that show nothing but are not formatting characters: the
     * combining grapheme joiner, variation selectors, and the Hangul, Khmer and
     * Mongolian fillers. Inside a name or a host, any of them can hide what
     * follows it.
     */
    private const INVISIBLE_CHARACTERS = '\x{034F}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{180B}-\x{180F}\x{3164}\x{FE00}-\x{FE0F}\x{FFA0}\x{E0100}-\x{E01EF}';

    /**
     * When the news happened, in UTC.
     *
     * The subscriber takes it once for the event and hands the same moment to
     * everyone it tells, however long working out each recipient's channels
     * takes. A notification built directly takes it as it is built.
     *
     * A push is described later, in a queue worker, once per recipient and per
     * channel and again on every retry. Asking the clock then would report when
     * a worker got round to it, so the moment travels with the notification
     * instead.
     */
    public CarbonImmutable $occurredAt;

    /**
     * Everything after $channels comes from the subscriber, which works it out
     * once for the event and hands the same answers to everyone it tells. Pass
     * those arguments by name: any added later will be appended after them, and
     * optional, so a notification built directly keeps working.
     *
     * The site's name is read from the site the subscriber already loads to
     * find the owner, so describing a push never asks the database for it, and
     * a site renamed while the push waits in a queue does not change what the
     * push says about the event.
     *
     * @param  Model  $subject  The monitor, heartbeat or reported exception the news is about.
     * @param  array<int, string>  $channels  The channels this recipient is told on.
     * @param  int|string|null  $ownerId  Whose news it is. A push cannot be described without it.
     * @param  int|null  $incidentId  The outage the news belongs to, or null when it belongs to none.
     * @param  bool  $timeSensitive  Whether the news should break through the recipient's focus.
     * @param  CarbonInterface|null  $occurredAt  When the news happened; the moment it is built when null.
     * @param  string|null  $siteName  The site's name when the event happened, or null when there is none to give.
     */
    public function __construct(
        public Model $subject,
        public array $channels,
        public int|string|null $ownerId = null,
        public ?int $incidentId = null,
        public bool $timeSensitive = false,
        ?CarbonInterface $occurredAt = null,
        public ?string $siteName = null,
    ) {
        $this->occurredAt = CarbonImmutable::instance($occurredAt ?? CarbonImmutable::now())->utc();
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    /**
     * Each kind of notification says for itself what its push reads like,
     * through buildPushMessage(). Until one does, asking is a mistake worth
     * hearing about rather than a push that says nothing useful.
     */
    public function toPush(object $notifiable): PushMessage
    {
        throw new LogicException(sprintf(
            '%s does not describe itself for push delivery; it needs its own toPush().',
            static::class,
        ));
    }

    /**
     * The push message for this notification, from the words a subclass
     * chooses and everything the subscriber already worked out.
     *
     * The moment is the one taken when the notification was built, unless the
     * subclass passes a more authoritative time of its own.
     *
     * The title and body are cleaned to one line of plain text and trimmed to
     * PushMessage::TITLE_MAX_LENGTH and PushMessage::BODY_MAX_LENGTH, so no push
     * described here is longer than the contract allows.
     *
     * A notification built outside the subscriber has no owner, and a push
     * with no owner cannot be routed to anyone's devices — so that fails
     * loudly here rather than being guessed.
     */
    protected function buildPushMessage(string $eventType, string $title, string $body, ?CarbonInterface $occurredAt = null): PushMessage
    {
        if ($this->ownerId === null) {
            throw new LogicException(sprintf(
                '%s was built without an owner, so it cannot describe a push; pass the owner key when constructing it.',
                static::class,
            ));
        }

        [$destinationType, $destinationId] = $this->pushDestination();

        return new PushMessage(
            eventType: $eventType,
            title: self::fitPushText($title, PushMessage::TITLE_MAX_LENGTH),
            body: self::fitPushText($body, PushMessage::BODY_MAX_LENGTH),
            ownerId: $this->ownerId,
            destinationType: $destinationType,
            destinationId: $destinationId,
            incidentId: $this->incidentId,
            occurredAt: $occurredAt === null ? $this->occurredAt : CarbonImmutable::instance($occurredAt)->utc(),
            timeSensitive: $this->timeSensitive,
        );
    }

    /**
     * A push body: a sentence about the subject, with `:subject` standing for
     * the name a person knows it by.
     *
     * The name is the one part of a push that can be any length — a deep
     * subdomain, a long job name, a long exception class — so it is the part
     * that gives way when the body would not fit, and the sentence around it
     * stays whole.
     *
     * A $siteSentence says the same thing with `:site` standing for the site's
     * name as well, and is used whenever there is a site name to give. Both
     * names then give way, each keeping at least half the room the sentence
     * leaves, and a short one giving what it does not need to the other.
     *
     * Pass a name, never a status or a failure reason. A push is described in a
     * queue worker that reloads the subject, by which time a failed job may be
     * running again; the words have to be about the event, not about whatever
     * the worker finds — and failure output is exactly where secrets and
     * traces turn up.
     *
     * A name that leaves nothing to read once it gives way — one character
     * built from hundreds of marks, say — is no name at all. A monitor or a
     * scheduled job is then called by its number, as it is when it has no name;
     * anything else is described by $unnamedSentence and $unnamedSiteSentence,
     * when they are given, which say the same thing without naming it.
     */
    protected function pushBody(
        string $sentence,
        string $subjectName,
        ?string $siteSentence = null,
        ?string $unnamedSentence = null,
        ?string $unnamedSiteSentence = null,
    ): string {
        [$body, $named] = $this->fillPushSentence($sentence, $subjectName, $siteSentence);

        if ($named) {
            return $body;
        }

        $number = self::pushNumberFor($this->subject);

        return match (true) {
            $number !== null => $this->fillPushSentence($sentence, $number, $siteSentence)[0],
            $unnamedSentence !== null => $this->fillPushSentence($unnamedSentence, '', $unnamedSiteSentence)[0],
            default => $body,
        };
    }

    /**
     * A push body with the subject's name, and the site's when there is one,
     * each given its share of the room.
     *
     * @return array{string, bool} the body, and whether it leaves any of the subject's name to read
     */
    private function fillPushSentence(string $sentence, string $subjectName, ?string $siteSentence): array
    {
        $subjectName = self::cleanPushText($subjectName);
        $siteName = self::cleanPushText((string) $this->siteName);

        if ($siteSentence === null || $siteName === '') {
            $room = PushMessage::BODY_MAX_LENGTH - mb_strlen(str_replace(':subject', '', $sentence));
            $subject = self::fitPushText($subjectName, $room);

            return [strtr($sentence, [':subject' => $subject]), self::keepsSomethingToRead($subject, $subjectName)];
        }

        $room = PushMessage::BODY_MAX_LENGTH - mb_strlen(str_replace([':subject', ':site'], '', $siteSentence));
        $siteRoom = min(mb_strlen($siteName), max(intdiv($room, 2), $room - mb_strlen($subjectName)));
        $subject = self::fitPushText($subjectName, $room - $siteRoom);

        return [
            strtr($siteSentence, [':subject' => $subject, ':site' => self::fitPushText($siteName, $siteRoom)]),
            self::keepsSomethingToRead($subject, $subjectName),
        ];
    }

    /**
     * The name a monitor goes by in a push: the host of its URL, and nothing
     * else from it.
     *
     * Not Monitor::host(), which gives back the whole URL when PHP cannot parse
     * it — and a URL PHP gives up on, with an impossible port say, can still be
     * saved, while a legacy import checks nothing at all. Credentials, paths
     * and query strings are where secrets sit, so with no host to be found the
     * monitor's own name stands in, and failing that its number.
     */
    protected function pushNameForMonitor(Monitor $monitor): string
    {
        $url = trim((string) $monitor->url);
        $host = parse_url($url, PHP_URL_HOST);

        if (! self::isBareHost($host) && preg_match(self::URL_HOST_PATTERN, $url, $matches) === 1) {
            $host = $matches[1];
        }

        if (self::isBareHost($host)) {
            return $host;
        }

        return self::hasSomethingToRead((string) $monitor->name)
            ? (string) $monitor->name
            : (string) self::pushNumberFor($monitor);
    }

    /**
     * The name a scheduled job goes by in a push: its own, or its number when
     * that name is nothing a person could read.
     */
    protected function pushNameForHeartbeat(MonitorHeartbeat $heartbeat): string
    {
        return self::hasSomethingToRead((string) $heartbeat->name)
            ? (string) $heartbeat->name
            : (string) self::pushNumberFor($heartbeat);
    }

    /**
     * The name a reported error goes by in a push: its class alone, without
     * its namespace — or null when that leaves nothing a person could read.
     *
     * PHP names an anonymous class after the class it extends, followed by a
     * NUL and the file and line it was declared on, so the push names the class
     * it extends and leaves the path behind. An anonymous class extending
     * nothing is named only "class", which says nothing, so it is unnamed too.
     */
    protected function pushNameForException(MonitorSiteException $exception): ?string
    {
        $class = (string) $exception->exception_class;
        $name = class_basename(Str::before($class, '@anonymous'));

        return ! self::hasSomethingToRead($name) || (self::cleanPushText($name) === 'class' && str_contains($class, '@anonymous'))
            ? null
            : $name;
    }

    /**
     * Whether a host is only a host, recognised by what it may contain rather
     * than by what it may not: letters, digits, marks, dots and hyphens, not
     * starting with a mark, a dot or a hyphen — or an IPv6 address in brackets.
     *
     * Anything else means the URL is not naming a host — a percent-escaped
     * delimiter, a query typed without its question mark, a fullwidth slash,
     * the underscore PHP's parser leaves where a control character was, or a
     * variation selector or filler that shows nothing while what follows it
     * reads as part of the host — so none of it is said. An IPv6 address with
     * a zone falls back too.
     *
     * @phpstan-assert-if-true non-empty-string $host
     */
    private static function isBareHost(mixed $host): bool
    {
        return is_string($host)
            && preg_match('~\A(?:\[[0-9a-f:.]+\]|(?![\p{M}.-])(?:(?!['.self::INVISIBLE_CHARACTERS.'])[\p{L}\p{N}\p{M}.-])+)\z~iu', $host) === 1;
    }

    /**
     * One line of plain text no longer than $max code points, ending in an
     * ellipsis when something had to be cut — and nothing at all when there is
     * no room.
     *
     * Text is cut between characters as a person sees them, never between the
     * halves of a flag or a letter and its accent, while its length is counted
     * in code points, the way the limits are.
     *
     * Only as many code points as there is room for are split into characters.
     * A character ending past the room is dropped either way, and splitting the
     * whole of a long run of flags takes seconds.
     */
    private static function fitPushText(string $text, int $max): string
    {
        if ($max < 1) {
            return '';
        }

        $text = self::cleanPushText($text);

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        preg_match_all('/\X/u', mb_substr($text, 0, $max), $characters);

        $kept = '';
        $length = 0;

        foreach ($characters[0] as $character) {
            $length += mb_strlen($character);

            if ($length > $max - 1) {
                break;
            }

            $kept .= $character;
        }

        return rtrim($kept).'…';
    }

    /**
     * One line of plain text: no control characters, and none of the invisible
     * characters — bidirectional overrides, zero-width spaces and joiners,
     * variation selectors, fillers — that can make a name read as something
     * other than what it is, or hide what follows it. A lock screen shows them
     * exactly as given. Losing the zero-width joiner splits an emoji sequence
     * into its parts, and losing a variation selector can show an emoji as a
     * plain symbol, which are the lesser harms.
     */
    private static function cleanPushText(string $text): string
    {
        $text = (string) preg_replace('/[\p{Cf}'.self::INVISIBLE_CHARACTERS.']+/u', '', mb_scrub($text));

        return Str::squish((string) preg_replace('/\p{Cc}+/u', ' ', $text));
    }

    /**
     * Whether text gives a person anything to read once cleaned: something
     * other than marks, which with no letter to sit on show nothing, or a stray
     * accent.
     */
    private static function hasSomethingToRead(string $text): bool
    {
        return preg_match('/[^\p{M}\s]/u', self::cleanPushText($text)) === 1;
    }

    /**
     * Whether text fitted into a push keeps anything of the name to read, not
     * counting the ellipsis that says the rest was cut.
     */
    private static function keepsSomethingToRead(string $fitted, string $name): bool
    {
        return self::hasSomethingToRead($fitted === $name ? $fitted : mb_substr($fitted, 0, -1));
    }

    /**
     * What a monitor or a scheduled job is called when its name gives nothing
     * to read: its number. Nothing else is known to a person by its number.
     */
    private static function pushNumberFor(Model $subject): ?string
    {
        return match (true) {
            $subject instanceof Monitor => "Monitor #{$subject->getKey()}",
            $subject instanceof MonitorHeartbeat => "Heartbeat #{$subject->getKey()}",
            default => null,
        };
    }

    /**
     * Where tapping the push should lead: the subject itself.
     *
     * @return array{string, int|string}
     */
    protected function pushDestination(): array
    {
        $destinationType = match (true) {
            $this->subject instanceof Monitor => PushMessage::DESTINATION_MONITOR,
            $this->subject instanceof MonitorHeartbeat => PushMessage::DESTINATION_HEARTBEAT,
            $this->subject instanceof MonitorSiteException => PushMessage::DESTINATION_EXCEPTION,
            default => throw new LogicException(sprintf(
                '%s has no push destination for a %s subject.',
                static::class,
                $this->subject::class,
            )),
        };

        return [$destinationType, $this->subject->getKey()];
    }

    /**
     * Add the "go and look" button, when this application has somewhere to
     * point at. An install with no UI wired still sends a usable email.
     */
    protected function withAction(MailMessage $message, string $label = 'View in monitoring'): MailMessage
    {
        $url = Monitoring::urlFor($this->subject);

        return $url === null ? $message : $message->action($label, $url);
    }
}
