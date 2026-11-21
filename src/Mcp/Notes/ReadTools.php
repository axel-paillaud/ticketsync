<?php

namespace App\Mcp\Notes;

use App\OrgNotes\OrgNotesGit;
use App\OrgNotes\OrgNotesReader;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Read-only MCP tools over the user's org-mode notes. Every call is logged
 * to the "org_notes" channel (var/log/org_notes.log in prod): who read what.
 */
#[WithMonologChannel('org_notes')]
final class ReadTools
{
    public function __construct(
        private OrgNotesReader $reader,
        private OrgNotesGit $git,
        private Security $security,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'list_notes',
        description: 'List the org files of the user\'s notes, most recently modified first, with their title. '
            .'Start here to find where something is written (e.g. restauration-james-cook.org for the Mercedes 207D).',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: false),
    )]
    public function listNotes(
        #[Schema(description: 'Optional sub-directory, e.g. "freelance". Omit for all notes.', maxLength: 200)]
        ?string $directory = null,
    ): array {
        return $this->run('list_notes', ['directory' => $directory], function () use ($directory): array {
            $files = $this->reader->listFiles($directory);

            return ['count' => \count($files), 'files' => $files];
        });
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'outline_note',
        description: 'Headings of one org file (line number, level, TODO state, title, tags), to find a section '
            .'before reading it with read_note, or to suggest a precise refileTarget for capture_note.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: false),
    )]
    public function outlineNote(
        #[Schema(description: 'Path from list_notes, e.g. "restauration-james-cook.org".', minLength: 1, maxLength: 300)]
        string $path,
        #[Schema(description: 'Deepest heading level returned.', minimum: 1, maximum: 9)]
        int $maxLevel = 3,
    ): array {
        return $this->run('outline_note', ['path' => $path], function () use ($path, $maxLevel): array {
            $headings = $this->reader->outline($path, $maxLevel);

            return ['path' => $path, 'count' => \count($headings), 'headings' => $headings];
        });
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'read_note',
        description: 'Read an org file, or only one section of it (headingLine = line of a heading from outline_note, '
            .'the whole subtree is returned). Long content is cut: call again with fromLine = nextLine to continue. '
            .'Prefer reading one section of a big file over the whole file.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: false),
    )]
    public function readNote(
        #[Schema(description: 'Path from list_notes.', minLength: 1, maxLength: 300)]
        string $path,
        #[Schema(description: 'Line of a heading (from outline_note or search_notes): read only that subtree.', minimum: 1)]
        ?int $headingLine = null,
        #[Schema(description: 'Continue a truncated read from this line (the nextLine of the previous answer).', minimum: 1)]
        ?int $fromLine = null,
    ): array {
        return $this->run('read_note', ['path' => $path, 'headingLine' => $headingLine, 'fromLine' => $fromLine], fn (): array => $this->reader->read($path, $headingLine, $fromLine));
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'search_notes',
        description: 'Find the lines of the notes containing all the words of the query, ignoring case and accents. '
            .'Each result gives the file, the line and the heading path it is under; read more with read_note.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: false),
    )]
    public function searchNotes(
        #[Schema(description: 'Words to find, e.g. "primaire zinc".', minLength: 1, maxLength: 200)]
        string $query,
        #[Schema(description: 'Optional sub-directory to search in.', maxLength: 200)]
        ?string $directory = null,
    ): array {
        return $this->run('search_notes', ['query' => $query, 'directory' => $directory], function () use ($query, $directory): array {
            $found = $this->reader->search($query, $directory);

            return ['count' => \count($found['results'])] + $found;
        });
    }

    /**
     * Shared checks, pull and audit log of every read tool.
     *
     * @param array<string, mixed>          $arguments logged as is
     * @param callable(): array<string, mixed> $read
     *
     * @return array<string, mixed>
     */
    private function run(string $tool, array $arguments, callable $read): array
    {
        // Also enforced by access_control on /mcp; kept here as a second lock.
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new ToolCallException('Notes are admin-only.');
        }

        $fresh = $this->git->refresh();
        $context = ['tool' => $tool, 'user' => $this->security->getUser()?->getUserIdentifier()] + array_filter($arguments, static fn ($v) => $v !== null);

        try {
            $result = $read();
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->logger->info('org-notes read refused', $context + ['reason' => $e->getMessage()]);

            throw new ToolCallException($e->getMessage());
        }

        $this->logger->info('org-notes read', $context);

        if (!$fresh) {
            $result['warnings'] = ['Could not pull the latest notes: this is the last copy synced on the server.'];
        }

        return $result;
    }
}
