<?php

namespace App\Mcp\Notes;

use App\OrgNotes\OrgCapture;
use App\OrgNotes\OrgNotesGit;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * MCP tools of the notes server (/mcp/notes): capture notes into the inbox
 * of the user's org-mode notes.
 */
final class CaptureTools
{
    public function __construct(
        private OrgNotesGit $notes,
        private Security $security,
        #[Autowire(param: 'app.org_notes.timezone')]
        private string $timezone,
    ) {}

    /**
     * @param list<string>                              $tags
     * @param list<array{start?: mixed, end?: mixed}>   $clocks
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'capture_note',
        description: 'Save a note to the inbox of the user\'s org-mode notes (one new file per note, the user refiles it later in Emacs). '
            .'Use it for ideas and for work logs, e.g. "today I worked from 8 to 12, I primed the part under the bench seat...". '
            .'Write a clean, faithful synthesis of what the user said, in the user\'s language (usually French): fix the dictation, '
            .'structure it (short paragraphs, "- " lists), but never invent facts, numbers or steps. '
            .'Times are only clocked when the user gives them: then pass them in "clocks", the server writes the org CLOCK lines. '
            .'To correct a note just captured (e.g. "no, it was 8:30"), call again with replaceId = the returned id and the full corrected note.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
    )]
    public function captureNote(
        #[Schema(description: 'Heading of the note: short, specific, no org markup, e.g. "Primaire au zinc sous la banquette".', minLength: 1, maxLength: OrgCapture::MAX_TITLE_LENGTH)]
        string $title,
        #[Schema(description: 'Body in org-mode syntax (plain paragraphs, "- " lists, *bold*, /italic/). No headings needed. May be empty.', maxLength: OrgCapture::MAX_BODY_LENGTH)]
        string $body = '',
        #[Schema(
            description: 'Optional org tags, e.g. ["mercedes"] for the restoration of the Mercedes 207D, ["idee"] for an idea.',
            items: ['type' => 'string'],
            maxItems: OrgCapture::MAX_TAGS,
        )]
        array $tags = [],
        #[Schema(
            description: 'Work periods stated by the user, in their local time (Europe/Paris), ISO 8601 without offset, '
                .'e.g. [{"start": "2026-11-21T08:00", "end": "2026-11-21T12:00"}]. Resolve "today", "this morning"... against the current date. '
                .'Leave empty when the user gives no times: never guess them.',
            items: [
                'type' => 'object',
                'properties' => [
                    'start' => ['type' => 'string', 'description' => 'e.g. "2026-11-21T08:00"'],
                    'end' => ['type' => 'string', 'description' => 'e.g. "2026-11-21T12:00"'],
                ],
                'required' => ['start', 'end'],
                'additionalProperties' => false,
            ],
            maxItems: OrgCapture::MAX_CLOCKS,
        )]
        array $clocks = [],
        #[Schema(description: 'Optional hint for the user\'s sorting: where the note probably belongs, e.g. "restauration-james-cook.org > Carrosserie > Caisson de siège".', maxLength: 300)]
        ?string $refileTarget = null,
        #[Schema(description: 'Only to correct a note already captured and not yet refiled: its id, as returned by the previous call.', pattern: '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')]
        ?string $replaceId = null,
    ): array {
        // Also enforced by access_control on /mcp; kept here as a second lock.
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            throw new ToolCallException('Notes are admin-only.');
        }

        try {
            $capture = OrgCapture::create(
                $replaceId ?? self::uuid4(),
                $title,
                $body,
                $tags,
                $clocks,
                $refileTarget,
                new \DateTimeImmutable('now', new \DateTimeZone($this->timezone)),
            );
        } catch (\InvalidArgumentException $e) {
            throw new ToolCallException($e->getMessage());
        }

        try {
            $saved = $this->notes->save($capture, replace: $replaceId !== null);
        } catch (\RuntimeException $e) {
            throw new ToolCallException($e->getMessage());
        }

        return [
            'result' => $saved->result,
            'id' => $capture->id,
            'file' => $saved->path,
            'pushed' => $saved->pushed,
            'warnings' => $saved->warnings,
            'clockedMinutes' => $capture->totalMinutes(),
            'org' => $capture->toOrg(),
        ];
    }

    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
