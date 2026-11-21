<?php

namespace App\OrgNotes;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Read-only access to the .org files of the notes clone (ORG_NOTES_DIR).
 *
 * Every path coming from the model goes through resolve(): relative, no
 * hidden segment, ".org" only, inside the clone once symlinks are resolved,
 * and not excluded by ORG_NOTES_EXCLUDE (comma-separated globs relative to
 * the root, e.g. "tma.org,freelance/*").
 */
final class OrgNotesReader
{
    /** Per call, so one answer stays small enough for the model. */
    public const MAX_READ_BYTES = 60000;
    public const MAX_SEARCH_RESULTS = 50;

    private const HEADING_PATTERN = '/^(\*+)\s+(?:(TODO|DONE|WAIT|NEXT|HOLD|CANCELLED|CANCELED|PROJ|STRT|KILL)\s+)?(.*?)(?:\s+(:[\p{L}\p{N}_@#%:]+:))?\s*$/u';

    private string $root;

    /** @var list<string> */
    private array $exclude;

    public function __construct(
        #[Autowire(env: 'ORG_NOTES_DIR')]
        string $directory,
        #[Autowire(env: 'ORG_NOTES_EXCLUDE')]
        string $exclude,
    ) {
        $this->root = rtrim($directory, '/');
        $this->exclude = array_values(array_filter(array_map('trim', explode(',', $exclude))));
    }

    public function isConfigured(): bool
    {
        return $this->root !== '' && is_dir($this->root.'/.git');
    }

    /**
     * @return list<array{path: string, title: string, bytes: int, modified: ?string}> most recently modified first
     */
    public function listFiles(?string $directory = null): array
    {
        $this->assertConfigured();
        $prefix = $directory === null || trim($directory, '/') === '' ? '' : $this->resolveDirectory($directory).'/';

        $root = realpath($this->root);
        $modified = $this->lastCommitDates();
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root.'/'.$prefix, \FilesystemIterator::SKIP_DOTS),
            // Never descend into hidden directories (.git) nor follow symlinks.
            static fn (\SplFileInfo $file) => !str_starts_with($file->getFilename(), '.') && !$file->isLink(),
        ));
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $path = substr($file->getPathname(), \strlen($root) + 1);
            if (!$file->isFile() || $file->getExtension() !== 'org' || $this->isExcluded($path)) {
                continue;
            }
            $files[] = [
                'path' => $path,
                'title' => $this->titleOf($file->getPathname()),
                'bytes' => $file->getSize(),
                'modified' => $modified[$path] ?? null,
            ];
        }

        usort($files, static fn ($a, $b) => [$b['modified'] ?? '', $a['path']] <=> [$a['modified'] ?? '', $b['path']]);

        return $files;
    }

    /**
     * Headings of a file, to find a section before reading it.
     *
     * @return list<array{line: int, level: int, todo: ?string, title: string, tags: list<string>}>
     */
    public function outline(string $path, int $maxLevel = 3): array
    {
        $outline = [];
        foreach ($this->lines($this->resolve($path)) as $i => $line) {
            $heading = self::parseHeading($line);
            if ($heading !== null && $heading['level'] <= $maxLevel) {
                $outline[] = ['line' => $i + 1] + $heading;
            }
        }

        return $outline;
    }

    /**
     * A whole file, or the subtree of the heading at $line, from $fromLine on,
     * cut at MAX_READ_BYTES on a line boundary.
     *
     * @return array{path: string, content: string, firstLine: int, lastLine: int, totalLines: int, truncated: bool, nextLine: ?int}
     */
    public function read(string $path, ?int $headingLine = null, ?int $fromLine = null): array
    {
        $lines = $this->lines($this->resolve($path));
        $total = \count($lines);

        $start = 1;
        $end = $total;
        if ($headingLine !== null) {
            $heading = self::parseHeading($lines[$headingLine - 1] ?? '');
            if ($heading === null) {
                throw new \InvalidArgumentException(sprintf('Line %d of %s is not a heading: use the line numbers of outline_note.', $headingLine, $path));
            }
            $start = $headingLine;
            for ($i = $headingLine; $i < $total; ++$i) {
                $next = self::parseHeading($lines[$i]);
                if ($next !== null && $next['level'] <= $heading['level']) {
                    $end = $i;
                    break;
                }
            }
        }
        if ($fromLine !== null) {
            if ($fromLine < $start || $fromLine > max($end, 1)) {
                throw new \InvalidArgumentException(sprintf('fromLine must be between %d and %d.', $start, $end));
            }
            $start = $fromLine;
        }

        $content = '';
        $last = $start - 1;
        for ($i = $start; $i <= $end; ++$i) {
            $line = $lines[$i - 1]."\n";
            if ($content !== '' && \strlen($content) + \strlen($line) > self::MAX_READ_BYTES) {
                break;
            }
            $content .= $line;
            $last = $i;
        }
        $truncated = $last < $end;

        return [
            'path' => $this->normalize($path),
            'content' => $content,
            'firstLine' => $start,
            'lastLine' => $last,
            'totalLines' => $total,
            'truncated' => $truncated,
            'nextLine' => $truncated ? $last + 1 : null,
        ];
    }

    /**
     * Lines containing every word of the query, ignoring case and accents
     * ("carrosserie" finds "Carrosserie", "peinture" finds "Peintüre").
     *
     * @return array{results: list<array{path: string, line: int, text: string, heading: ?string}>, truncated: bool}
     */
    public function search(string $query, ?string $directory = null): array
    {
        $words = array_values(array_filter(preg_split('/\s+/u', self::fold($query)) ?: []));
        if ($words === []) {
            throw new \InvalidArgumentException('The query cannot be empty.');
        }

        $results = [];
        foreach ($this->listFiles($directory) as $file) {
            $headings = [];
            foreach ($this->lines($this->root.'/'.$file['path']) as $i => $line) {
                $heading = self::parseHeading($line);
                if ($heading !== null) {
                    $headings = \array_slice($headings, 0, $heading['level'] - 1);
                    $headings[] = $heading['title'];
                }

                $folded = self::fold($line);
                foreach ($words as $word) {
                    if (!str_contains($folded, $word)) {
                        continue 2;
                    }
                }
                if (\count($results) === self::MAX_SEARCH_RESULTS) {
                    return ['results' => $results, 'truncated' => true];
                }
                $results[] = [
                    'path' => $file['path'],
                    'line' => $i + 1,
                    'text' => mb_strimwidth(trim($line), 0, 300, '…'),
                    'heading' => $headings === [] ? null : implode(' > ', $headings),
                ];
            }
        }

        return ['results' => $results, 'truncated' => false];
    }

    /**
     * Absolute path of a readable note.
     *
     * @throws \InvalidArgumentException with the same message for every refusal,
     *                                   so excluded files cannot be told apart from missing ones
     */
    private function resolve(string $path): string
    {
        $this->assertConfigured();
        $relative = $this->normalize($path);
        $notFound = new \InvalidArgumentException(sprintf('No readable note "%s". Use list_notes to see the files.', $path));

        if (!str_ends_with($relative, '.org') || !$this->isSafeRelative($relative) || $this->isExcluded($relative)) {
            throw $notFound;
        }

        $absolute = realpath($this->root.'/'.$relative);
        $root = realpath($this->root);
        // realpath() resolves symlinks: what it points to must still be a note of the clone.
        if ($absolute === false || $root === false || $absolute !== $root.'/'.$relative || !is_file($absolute)) {
            throw $notFound;
        }

        return $absolute;
    }

    private function resolveDirectory(string $directory): string
    {
        $relative = trim($this->normalize($directory), '/');
        $root = realpath($this->root);
        $absolute = realpath($this->root.'/'.$relative);
        if (!$this->isSafeRelative($relative) || $absolute === false || $absolute !== $root.'/'.$relative || !is_dir($absolute)) {
            throw new \InvalidArgumentException(sprintf('No directory "%s" in the notes.', $directory));
        }

        return $relative;
    }

    private function normalize(string $path): string
    {
        return ltrim(str_replace('\\', '/', trim($path)), '/');
    }

    /**
     * No "..", no hidden segment (.git, .env...), no NUL byte.
     */
    private function isSafeRelative(string $relative): bool
    {
        if ($relative === '' || str_contains($relative, "\0")) {
            return false;
        }
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || str_starts_with($segment, '.')) {
                return false;
            }
        }

        return true;
    }

    private function isExcluded(string $relative): bool
    {
        foreach ($this->exclude as $pattern) {
            if (fnmatch($pattern, $relative, \FNM_PATHNAME) || str_starts_with($relative, rtrim($pattern, '/*').'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function lines(string $absolute): array
    {
        $content = file_get_contents($absolute);
        if ($content === false) {
            throw new \RuntimeException('Could not read the note.');
        }

        return explode("\n", rtrim(str_replace(["\r\n", "\r"], "\n", $content), "\n"));
    }

    /**
     * "#+title:", else the first heading, else nothing.
     */
    private function titleOf(string $absolute): string
    {
        $handle = fopen($absolute, 'r');
        if ($handle === false) {
            return '';
        }
        $title = '';
        for ($i = 0; $i < 30 && ($line = fgets($handle)) !== false; ++$i) {
            if (preg_match('/^#\+(title|description):\s*(.+)$/i', $line, $m) === 1) {
                $title = trim($m[2]);
                break;
            }
            $heading = self::parseHeading(rtrim($line, "\n"));
            if ($heading !== null) {
                $title = $heading['title'];
                break;
            }
        }
        fclose($handle);

        return mb_strimwidth($title, 0, 120, '…');
    }

    /**
     * Date of the last commit touching each file, from one "git log".
     *
     * @return array<string, string> path => ISO 8601 date
     */
    private function lastCommitDates(): array
    {
        $process = new Process(['git', 'log', '--format=%x00%cI', '--name-only', '--no-renames'], $this->root, ['GIT_TERMINAL_PROMPT' => '0'], timeout: 10);
        $process->run();
        if (!$process->isSuccessful()) {
            return [];
        }

        $dates = [];
        $date = null;
        foreach (explode("\n", $process->getOutput()) as $line) {
            if (str_starts_with($line, "\0")) {
                $date = substr($line, 1);
            } elseif ($line !== '' && $date !== null) {
                $dates[$line] ??= $date;
            }
        }

        return $dates;
    }

    /**
     * @return array{level: int, todo: ?string, title: string, tags: list<string>}|null
     */
    private static function parseHeading(string $line): ?array
    {
        if (!str_starts_with($line, '*') || preg_match(self::HEADING_PATTERN, $line, $m) !== 1) {
            return null;
        }

        return [
            'level' => \strlen($m[1]),
            'todo' => ($m[2] ?? '') !== '' ? $m[2] : null,
            'title' => $m[3],
            'tags' => isset($m[4]) ? array_values(array_filter(explode(':', $m[4]))) : [],
        ];
    }

    private static function fold(string $text): string
    {
        static $transliterator = null;
        $transliterator ??= \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC; Lower');

        return $transliterator->transliterate($text) ?: mb_strtolower($text);
    }

    private function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('The notes repository is not configured on the server (ORG_NOTES_DIR).');
        }
    }
}
