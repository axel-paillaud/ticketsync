<?php

namespace App\OrgNotes;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Writes captured notes to the inbox/ directory of the org-notes git clone
 * (ORG_NOTES_DIR), then commits and pushes.
 *
 * Conflicts are avoided by design rather than resolved: the server only
 * creates or rewrites its own files in inbox/, one file per note, and never
 * touches anything else. The user merges them into refile.org on their side.
 * So "pull --rebase" never conflicts, whatever the user changed meanwhile.
 *
 * The clone's own git config holds the SSH key (core.sshCommand) and the
 * remote; this class only runs plain git commands in it.
 */
final class OrgNotesGit
{
    public const INBOX_DIR = 'inbox';

    private const AUTHOR_NAME = 'ticketsync-bot';
    private const AUTHOR_EMAIL = 'ticketsync-bot@localhost';
    private const PUSH_ATTEMPTS = 3;
    private const GIT_TIMEOUT_SECONDS = 30;
    /** Reads pull at most this often, so a burst of tool calls stays fast. */
    private const REFRESH_INTERVAL_SECONDS = 60;

    public function __construct(
        #[Autowire(env: 'ORG_NOTES_DIR')]
        private string $directory,
        private LoggerInterface $logger,
    ) {
        $this->directory = rtrim($directory, '/');
    }

    public function isConfigured(): bool
    {
        return $this->directory !== '' && is_dir($this->directory.'/.git');
    }

    /**
     * Pulls before reading, unless the last pull is recent.
     *
     * @return bool false if the pull failed (the local copy is still readable)
     */
    public function refresh(): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        return $this->locked(function (): bool {
            // FETCH_HEAD is rewritten by every successful pull.
            $fetchHead = $this->directory.'/.git/FETCH_HEAD';
            clearstatcache(true, $fetchHead);
            if (is_file($fetchHead) && time() - filemtime($fetchHead) < self::REFRESH_INTERVAL_SECONDS) {
                return true;
            }

            return $this->pull();
        });
    }

    /**
     * Saves the note as a new inbox file, or rewrites the inbox file of the
     * note with the same ID (a correction, as long as the user has not
     * refiled it yet).
     *
     * @throws \RuntimeException if nothing could be saved
     */
    public function save(OrgCapture $capture, bool $replace): SaveResult
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('The notes repository is not configured on the server (ORG_NOTES_DIR).');
        }

        return $this->locked(function () use ($capture, $replace): SaveResult {
            $warnings = [];
            if (!$this->pull()) {
                $warnings[] = 'Could not pull the latest notes before writing (offline?): the note is saved, the push may fail.';
            }

            if ($replace) {
                $path = $this->findInboxFile($capture->id);
                if ($path === null) {
                    throw new \RuntimeException(sprintf(
                        'No note with ID %s in the inbox: it was already refiled by the user, or never existed. Create a new note instead.',
                        $capture->id,
                    ));
                }
                $this->write($path, $capture->toOrg(), overwrite: true);
            } else {
                $path = $this->newInboxPath($capture);
                $this->write($path, $capture->toOrg(), overwrite: false);
            }

            $this->git('add', ['add', '--', $path]);
            // Rewriting a note with the same content leaves nothing to commit.
            if (!$this->run(['diff', '--cached', '--quiet', '--', $path])) {
                $this->git('commit', [
                    '-c', 'user.name='.self::AUTHOR_NAME,
                    '-c', 'user.email='.self::AUTHOR_EMAIL,
                    'commit', '--quiet', '--no-verify',
                    '-m', ($replace ? 'inbox: update ' : 'inbox: ').$capture->title,
                    '--', $path,
                ]);
            }

            $pushed = $this->push();
            if (!$pushed) {
                // Still committed: the next capture pushes it along.
                $warnings[] = 'The note is committed on the server but could not be pushed yet; it will be pushed with the next note.';
            }

            return new SaveResult($path, $replace ? 'updated' : 'created', $pushed, $warnings);
        });
    }

    /**
     * One git operation at a time: two concurrent git runs in one clone would fail.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function locked(callable $callback): mixed
    {
        $lock = fopen($this->directory.'/.git/ticketsync-capture.lock', 'c');
        if ($lock === false || !flock($lock, \LOCK_EX)) {
            throw new \RuntimeException('Could not lock the notes repository.');
        }

        try {
            return $callback();
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }

    private function pull(): bool
    {
        if ($this->run(['pull', '--rebase', '--quiet'])) {
            return true;
        }
        // Never leave the clone in the middle of a rebase.
        if (is_dir($this->directory.'/.git/rebase-merge') || is_dir($this->directory.'/.git/rebase-apply')) {
            $this->run(['rebase', '--abort']);
        }

        return false;
    }

    private function push(): bool
    {
        for ($attempt = 1; $attempt <= self::PUSH_ATTEMPTS; ++$attempt) {
            if ($this->run(['push', '--quiet'])) {
                return true;
            }
            // Rejected because the user pushed meanwhile: catch up and retry.
            $this->pull();
        }

        return false;
    }

    /**
     * Relative path of the inbox file holding this note ID, or null.
     */
    private function findInboxFile(string $id): ?string
    {
        $pattern = '/^:ID:\s+'.preg_quote($id, '/').'\s*$/m';
        foreach (glob($this->directory.'/'.self::INBOX_DIR.'/*.org') ?: [] as $file) {
            if (preg_match($pattern, (string) file_get_contents($file)) === 1) {
                return self::INBOX_DIR.'/'.basename($file);
            }
        }

        return null;
    }

    /**
     * inbox/20261121-120512-primaire-au-zinc-sous-la-banquette-3f2a.org:
     * sorted by date, readable, and unique.
     */
    private function newInboxPath(OrgCapture $capture): string
    {
        $slug = (new AsciiSlugger('fr'))->slug($capture->title)->lower()->truncate(50, cut: false)->trimEnd('-')->toString();

        return sprintf(
            '%s/%s-%s-%s.org',
            self::INBOX_DIR,
            $capture->createdAt->format('Ymd-His'),
            $slug !== '' ? $slug : 'note',
            substr(str_replace('-', '', $capture->id), 0, 6),
        );
    }

    private function write(string $relativePath, string $content, bool $overwrite): void
    {
        $dir = $this->directory.'/'.self::INBOX_DIR;
        if (!is_dir($dir) && !mkdir($dir, 0o775) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create the inbox directory.');
        }

        // "x": never overwrite a file by accident when creating a note.
        $handle = fopen($this->directory.'/'.$relativePath, $overwrite ? 'w' : 'x');
        if ($handle === false || fwrite($handle, $content) !== \strlen($content) || !fclose($handle)) {
            throw new \RuntimeException(sprintf('Could not write %s.', $relativePath));
        }
    }

    /**
     * @param list<string> $arguments
     */
    private function git(string $label, array $arguments): void
    {
        if (!$this->run($arguments)) {
            throw new \RuntimeException(sprintf('git %s failed on the server (see the server logs).', $label));
        }
    }

    /**
     * @param list<string> $arguments
     */
    private function run(array $arguments): bool
    {
        $process = new Process(['git', ...$arguments], $this->directory, [
            // Fail instead of waiting for a password or a host key confirmation.
            'GIT_TERMINAL_PROMPT' => '0',
            // The SSH key is set by core.sshCommand in the clone: no override.
            'GIT_SSH_COMMAND' => false,
        ], timeout: self::GIT_TIMEOUT_SECONDS);

        try {
            $process->run();
        } catch (\Throwable $e) {
            $this->logger->error('org-notes: git {command} crashed: {message}', ['command' => $arguments[0], 'message' => $e->getMessage()]);

            return false;
        }

        if (!$process->isSuccessful()) {
            $this->logger->warning('org-notes: git {command} failed: {output}', [
                'command' => implode(' ', $arguments),
                'output' => trim($process->getErrorOutput()."\n".$process->getOutput()),
            ]);

            return false;
        }

        return true;
    }
}
