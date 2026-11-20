<?php

namespace App\Tests\Functional;

use App\Entity\Activity;
use App\Entity\Organization;
use App\Entity\Ticket;
use Symfony\Component\Mime\Email;

/**
 * Going beyond the monthly hours tolerance emails the organization once a month.
 */
class QuotaExceededEmailTest extends FunctionalTestCase
{
    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization->setMonthlyHours('3.00');
        $this->em->flush();
        $this->ticket = $this->createTicket($this->member);
    }

    public function testNoEmailWithinTolerance(): void
    {
        $this->logHours('3.75');

        $this->assertCount(0, $this->quotaEmails());
    }

    public function testEmailOnceWhenToleranceIsExceeded(): void
    {
        $this->logHours('3.75');
        $this->logHours('0.50');
        $this->logHours('1.00');

        $emails = $this->quotaEmails();
        $recipients = array_map(fn (Email $email) => $email->getTo()[0]->getAddress(), $emails);
        sort($recipients);
        $this->assertSame(['admin@example.com', 'member@example.com'], $recipients);
        $body = $emails[0]->getHtmlBody();
        $this->assertStringContainsString((new \DateTimeImmutable())->format('F Y'), $body);
        $this->assertStringContainsString((new \DateTimeImmutable('first day of next month'))->format('F j'), $body);
        $this->assertStringNotContainsString(', date,', $body);

        $this->em->clear();
        $organization = $this->em->getRepository(Organization::class)->find($this->organization->getId());
        $this->assertSame((new \DateTimeImmutable())->format('Y-m'), $organization->getQuotaExceededNotifiedMonth());
    }

    public function testHoursOfAnotherMonthDoNotTriggerEmail(): void
    {
        $this->logHours('10.00', new \DateTimeImmutable('first day of last month'));

        $this->assertCount(0, $this->quotaEmails());
    }

    public function testNoEmailWithoutMonthlyHours(): void
    {
        $this->organization->setMonthlyHours(null);
        $this->em->flush();
        $this->logHours('10.00');

        $this->assertCount(0, $this->quotaEmails());
    }

    /** @return Email[] */
    private function quotaEmails(): array
    {
        return array_values(array_filter(
            $this->getMailerMessages(),
            fn ($message) => $message instanceof Email
                && str_contains((string) $message->getSubject(), 'Maintenance package used'),
        ));
    }

    private function logHours(string $hours, ?\DateTimeImmutable $workDate = null): void
    {
        $activity = (new Activity())
            ->setDescription('Work')
            ->setHours($hours)
            ->setWorkDate($workDate ?? new \DateTimeImmutable('today'))
            ->setTicket($this->ticket)
            ->setCreatedBy($this->admin)
            ->setOrganization($this->organization);
        $this->em->persist($activity);
        $this->em->flush();
    }
}
