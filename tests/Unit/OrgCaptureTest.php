<?php

namespace App\Tests\Unit;

use App\OrgNotes\OrgCapture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Org rendering of a captured note: the model writes text, the server writes
 * the org syntax Emacs parses.
 */
class OrgCaptureTest extends TestCase
{
    private const ID = '3f2a9c4e-5b7d-4e21-9a0c-1d2e3f4a5b6c';

    public function testRendersAWorkLogWithClocks(): void
    {
        $capture = $this->capture(
            title: 'Primaire au zinc sous la banquette',
            body: "Préparation de la pièce extérieure.\n- dégraissage\n- primaire au zinc",
            tags: ['mercedes'],
            clocks: [
                ['start' => '2026-11-21T08:00', 'end' => '2026-11-21T12:00'],
                ['start' => '2026-11-21T13:30', 'end' => '2026-11-21T14:05'],
            ],
            refileTarget: 'restauration-james-cook.org > Carrosserie',
        );

        $this->assertSame(<<<'ORG'
            * Primaire au zinc sous la banquette :mercedes:
            :PROPERTIES:
            :ID:       3f2a9c4e-5b7d-4e21-9a0c-1d2e3f4a5b6c
            :CREATED:  [2026-11-21 sam. 14:10]
            :REFILE_TARGET: restauration-james-cook.org > Carrosserie
            :END:
            :LOGBOOK:
            CLOCK: [2026-11-21 sam. 13:30]--[2026-11-21 sam. 14:05] =>  0:35
            CLOCK: [2026-11-21 sam. 08:00]--[2026-11-21 sam. 12:00] =>  4:00
            :END:
            Préparation de la pièce extérieure.
            - dégraissage
            - primaire au zinc

            ORG, $capture->toOrg());
        $this->assertSame(275, $capture->totalMinutes());
    }

    public function testAnIdeaHasNoLogbook(): void
    {
        $org = $this->capture(title: 'Idée', body: '')->toOrg();

        $this->assertSame("* Idée\n:PROPERTIES:\n:ID:       ".self::ID."\n:CREATED:  [2026-11-21 sam. 14:10]\n:END:\n", $org);
    }

    public function testLongClockDurationIsAligned(): void
    {
        $org = $this->capture(clocks: [['start' => '2026-11-20T08:00', 'end' => '2026-11-20T20:30']])->toOrg();

        $this->assertStringContainsString('CLOCK: [2026-11-20 ven. 08:00]--[2026-11-20 ven. 20:30] => 12:30', $org);
    }

    public function testTimesWithAnOffsetAreConvertedToLocalTime(): void
    {
        $org = $this->capture(clocks: [['start' => '2026-11-21T07:00:00Z', 'end' => '2026-11-21T09:00:45+00:00']])->toOrg();

        $this->assertStringContainsString('CLOCK: [2026-11-21 sam. 08:00]--[2026-11-21 sam. 10:00] =>  2:00', $org);
    }

    public function testBodyHeadingsStayBelowTheNote(): void
    {
        $org = $this->capture(body: "* Étape 1\ntexte\n** Détail\n*gras* reste du gras")->toOrg();

        $this->assertStringContainsString("** Étape 1\ntexte\n*** Détail\n*gras* reste du gras", $org);
    }

    public function testTitleIsASingleLineWithoutStars(): void
    {
        $org = $this->capture(title: "** Une idée\nsur deux lignes ")->toOrg();

        $this->assertStringStartsWith("* Une idée sur deux lignes\n", $org);
    }

    #[DataProvider('invalidInputProvider')]
    public function testRejectsInvalidInput(array $arguments, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->capture(...$arguments);
    }

    public static function invalidInputProvider(): iterable
    {
        yield 'empty title' => [['title' => ' * '], 'title cannot be empty'];
        yield 'tag with a space' => [['tags' => ['two words']], 'Invalid tag'];
        yield 'not a time' => [['clocks' => [['start' => 'ce matin', 'end' => '2026-11-21T12:00']]], 'clocks[0].start'];
        yield 'missing end' => [['clocks' => [['start' => '2026-11-21T08:00']]], 'clocks[0].end'];
        yield 'invalid date' => [['clocks' => [['start' => '2026-02-30T08:00', 'end' => '2026-02-30T09:00']]], 'invalid date'];
        yield 'end before start' => [['clocks' => [['start' => '2026-11-21T12:00', 'end' => '2026-11-21T08:00']]], 'end must be after start'];
        yield 'too long' => [['clocks' => [['start' => '2026-11-19T08:00', 'end' => '2026-11-20T09:00']]], 'more than 24 hours'];
        yield 'in the future' => [['clocks' => [['start' => '2026-11-21T14:00', 'end' => '2026-11-21T18:00']]], 'in the future'];
    }

    /**
     * @param list<string> $tags
     * @param list<array<string, string>> $clocks
     */
    private function capture(
        string $title = 'Note',
        string $body = 'Texte.',
        array $tags = [],
        array $clocks = [],
        ?string $refileTarget = null,
    ): OrgCapture {
        $now = new \DateTimeImmutable('2026-11-21 14:10:33', new \DateTimeZone('Europe/Paris'));

        return OrgCapture::create(self::ID, $title, $body, $tags, $clocks, $refileTarget, $now);
    }
}
