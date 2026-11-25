<?php

namespace App\OrgNotes;

/**
 * One note captured by Claude (often dictated by voice), rendered as a
 * standalone org-mode file for the inbox: a single level-1 heading, so that
 * it can be appended as is to refile.org.
 *
 *     * Primaire au zinc sous la banquette :mercedes:
 *     :PROPERTIES:
 *     :ID:       3f2a9c4e-5b7d-4e21-9a0c-1d2e3f4a5b6c
 *     :CREATED:  [2026-11-21 sam. 12:05]
 *     :END:
 *     :LOGBOOK:
 *     CLOCK: [2026-11-21 sam. 08:00]--[2026-11-21 sam. 12:00] =>  4:00
 *     :END:
 *     Body...
 *
 * All input is validated here: the model writes the text, but never the org
 * syntax that Emacs parses (clock lines, drawers, timestamps).
 */
final class OrgCapture
{
    public const MAX_TITLE_LENGTH = 200;
    public const MAX_BODY_LENGTH = 20000;
    public const MAX_TAGS = 10;
    public const MAX_CLOCKS = 20;

    /** Day abbreviations used by Emacs in a French locale, as in the existing files. */
    private const DAYS = [1 => 'lun.', 2 => 'mar.', 3 => 'mer.', 4 => 'jeu.', 5 => 'ven.', 6 => 'sam.', 7 => 'dim.'];

    /** Time given by the model: date, time, optional seconds and UTC offset. */
    private const DATETIME_PATTERN = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:?\d{2})?$/';

    /**
     * @param list<string>                                                 $tags
     * @param list<array{start: \DateTimeImmutable, end: \DateTimeImmutable}> $clocks newest first
     */
    private function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $body,
        public readonly array $tags,
        public readonly array $clocks,
        public readonly ?string $refileTarget,
        public readonly \DateTimeImmutable $createdAt,
    ) {}

    /**
     * @param list<string>                                $tags
     * @param list<array{start?: mixed, end?: mixed}>     $clocks ISO 8601 local times, e.g. "2026-11-21T08:00"
     *
     * @throws \InvalidArgumentException with a message meant for the model
     */
    public static function create(
        string $id,
        string $title,
        string $body,
        array $tags,
        array $clocks,
        ?string $refileTarget,
        \DateTimeImmutable $now,
    ): self {
        $timezone = $now->getTimezone();

        // A heading is one line, and must not look like a deeper heading.
        $title = trim(ltrim(self::singleLine($title), '* '));
        if ($title === '') {
            throw new \InvalidArgumentException('The title cannot be empty.');
        }
        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            throw new \InvalidArgumentException(sprintf('The title is longer than %d characters.', self::MAX_TITLE_LENGTH));
        }

        $body = self::sanitizeBody($body);
        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw new \InvalidArgumentException(sprintf('The body is longer than %d characters.', self::MAX_BODY_LENGTH));
        }

        if (\count($tags) > self::MAX_TAGS) {
            throw new \InvalidArgumentException(sprintf('At most %d tags.', self::MAX_TAGS));
        }
        $tags = array_values(array_unique(array_map(static fn ($tag) => trim((string) $tag, " :\t"), $tags)));
        foreach ($tags as $tag) {
            if (preg_match('/^[\p{L}\p{N}_@#%]+$/u', $tag) !== 1) {
                throw new \InvalidArgumentException(sprintf('Invalid tag "%s": letters, digits, _ @ # %% only.', $tag));
            }
        }

        if (\count($clocks) > self::MAX_CLOCKS) {
            throw new \InvalidArgumentException(sprintf('At most %d clock entries.', self::MAX_CLOCKS));
        }
        $parsed = [];
        foreach ($clocks as $i => $clock) {
            $start = self::parseTime($clock['start'] ?? null, $timezone, sprintf('clocks[%d].start', $i));
            $end = self::parseTime($clock['end'] ?? null, $timezone, sprintf('clocks[%d].end', $i));
            if ($end <= $start) {
                throw new \InvalidArgumentException(sprintf('clocks[%d]: end must be after start.', $i));
            }
            if ($end->getTimestamp() - $start->getTimestamp() > 24 * 3600) {
                throw new \InvalidArgumentException(sprintf('clocks[%d]: a clock entry cannot last more than 24 hours.', $i));
            }
            $parsed[] = ['start' => $start, 'end' => $end];
        }
        // Org keeps the most recent clock first.
        usort($parsed, static fn ($a, $b) => $b['start'] <=> $a['start']);

        $refileTarget = $refileTarget === null ? null : self::singleLine($refileTarget);
        if ($refileTarget === '') {
            $refileTarget = null;
        }

        return new self($id, $title, $body, $tags, $parsed, $refileTarget, $now);
    }

    public function totalMinutes(): int
    {
        return array_sum(array_map(
            static fn (array $c) => intdiv($c['end']->getTimestamp() - $c['start']->getTimestamp(), 60),
            $this->clocks,
        ));
    }

    public function toOrg(): string
    {
        $heading = '* '.$this->title;
        if ($this->tags !== []) {
            $heading .= ' :'.implode(':', $this->tags).':';
        }

        $lines = [$heading, ':PROPERTIES:', self::property('ID', $this->id), self::property('CREATED', self::timestamp($this->createdAt))];
        if ($this->refileTarget !== null) {
            $lines[] = self::property('REFILE_TARGET', $this->refileTarget);
        }
        $lines[] = ':END:';

        if ($this->clocks !== []) {
            $lines[] = ':LOGBOOK:';
            foreach ($this->clocks as $clock) {
                $minutes = intdiv($clock['end']->getTimestamp() - $clock['start']->getTimestamp(), 60);
                $lines[] = sprintf(
                    'CLOCK: %s--%s => %2d:%02d',
                    self::timestamp($clock['start']),
                    self::timestamp($clock['end']),
                    intdiv($minutes, 60),
                    $minutes % 60,
                );
            }
            $lines[] = ':END:';
        }

        if ($this->body !== '') {
            $lines[] = $this->body;
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Org inactive timestamp, e.g. "[2026-11-21 sam. 08:00]".
     */
    public static function timestamp(\DateTimeImmutable $time): string
    {
        return sprintf('[%s %s %s]', $time->format('Y-m-d'), self::DAYS[(int) $time->format('N')], $time->format('H:i'));
    }

    /**
     * Same layout as Emacs (org-property-format "%-10s %s").
     */
    private static function property(string $name, string $value): string
    {
        return sprintf('%-10s %s', ':'.$name.':', $value);
    }

    private static function singleLine(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    /**
     * Headings inside the body are demoted one level, so they stay below the
     * note instead of becoming siblings of it (or of refile.org's headings).
     */
    private static function sanitizeBody(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        // No control characters except newline and tab.
        $body = preg_replace('/[^\P{C}\n\t]/u', '', $body) ?? '';
        $body = preg_replace('/^(\*+)(?=\s)/m', '*$1', $body) ?? '';

        return trim($body, "\n");
    }

    private static function parseTime(mixed $value, \DateTimeZone $timezone, string $field): \DateTimeImmutable
    {
        if (!\is_string($value) || preg_match(self::DATETIME_PATTERN, $value) !== 1) {
            throw new \InvalidArgumentException(sprintf('%s: expected an ISO 8601 time like "2026-11-21T08:00", got %s.', $field, json_encode($value)));
        }

        // Without offset: local time of the user. With one: converted to it.
        $time = new \DateTimeImmutable($value, $timezone);
        $date = substr($value, 0, 10);
        if (\DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone)?->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException(sprintf('%s: invalid date "%s".', $field, $date));
        }

        // Org clocks have minute precision: drop the seconds.
        $local = $time->setTimezone($timezone);

        return $local->setTime((int) $local->format('H'), (int) $local->format('i'));
    }
}
