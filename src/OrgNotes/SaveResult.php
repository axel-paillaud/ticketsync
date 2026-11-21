<?php

namespace App\OrgNotes;

final class SaveResult
{
    /**
     * @param string       $path     relative to the notes repository, e.g. "inbox/2026...-note-3f2a9c.org"
     * @param 'created'|'updated' $result
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly string $path,
        public readonly string $result,
        public readonly bool $pushed,
        public readonly array $warnings = [],
    ) {}
}
