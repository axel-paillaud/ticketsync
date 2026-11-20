<?php

namespace App\Tests\Functional;

use App\Entity\Activity;
use App\Entity\Ticket;

/**
 * The monthly package gauge: hidden for organizations billed on actual hours,
 * always visible to the client otherwise, with a notice only beyond the tolerance.
 */
class MonthlyQuotaDisplayTest extends FunctionalTestCase
{
    public function testNoGaugeWithoutMonthlyPackage(): void
    {
        $this->logHours($this->createTicket($this->member), '10.00');

        $this->rebootKernel();
        $this->client->loginUser($this->member);

        $this->client->request('GET', $this->ticketsUrl());
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('.quota-gauge');
    }

    public function testClientSeesGaugeWithoutNoticeWithinTolerance(): void
    {
        $this->setPackage('3.00');
        $ticket = $this->createTicket($this->member);
        $this->logHours($ticket, '3.50');
        // Last month's hours do not count
        $this->logHours($ticket, '5.00', new \DateTimeImmutable('first day of last month'));

        $this->rebootKernel();
        $this->client->loginUser($this->member);

        $this->client->request('GET', $this->ticketsUrl());
        $this->assertSelectorExists('.quota-gauge[data-quota-state="tolerated"]');
        $this->assertSelectorNotExists('.quota-gauge-admin');
        $this->assertSelectorNotExists('.quota-exceeded-notice');
        $this->assertSelectorTextContains('.quota-gauge', '3h30 used out of 3h');
        // ICU placeholders are rendered, not left raw
        $this->assertSelectorTextContains('.quota-gauge', (new \DateTimeImmutable())->format('F Y'));
        $this->assertSelectorTextNotContains('.quota-gauge', '{');

        $this->client->request('GET', $this->ticketsUrl().'/new');
        $this->assertSelectorNotExists('.quota-exceeded-notice');
    }

    public function testClientIsNotifiedBeyondTolerance(): void
    {
        $this->setPackage('3.00');
        $this->logHours($this->createTicket($this->member), '4.00');

        $this->rebootKernel();
        $this->client->loginUser($this->member);

        $this->client->request('GET', $this->ticketsUrl());
        $this->assertSelectorExists('.quota-gauge[data-quota-state="exceeded"] .quota-exceeded-notice');
        $nextMonth = (new \DateTimeImmutable('first day of next month'))->format('F j');
        $this->assertSelectorTextContains('.quota-exceeded-notice', $nextMonth);

        $this->client->request('GET', $this->ticketsUrl().'/new');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.quota-exceeded-notice');
    }

    public function testAdminSeesToleranceGauge(): void
    {
        $this->setPackage('3.00');
        $this->logHours($this->createTicket($this->member), '3.50');

        $this->rebootKernel();
        $this->client->loginUser($this->admin);

        $this->client->request('GET', $this->ticketsUrl());
        $this->assertSelectorExists('.quota-gauge-admin[data-quota-state="tolerated"]');
        $this->assertSelectorTextContains('.quota-gauge-admin', '3h30 / 3h (tolerance 30% up to 3h54)');
        $this->assertSelectorTextNotContains('.quota-gauge-admin', '{');

        $this->client->request('GET', sprintf('/admin/organizations/%d', $this->organization->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.quota-gauge-admin[data-quota-state="tolerated"]');
    }

    private function ticketsUrl(): string
    {
        return sprintf('/%s/tickets', $this->organization->getSlug());
    }

    private function setPackage(string $hours): void
    {
        $this->organization->setMonthlyHours($hours);
        $this->em->flush();
    }

    private function logHours(Ticket $ticket, string $hours, ?\DateTimeImmutable $workDate = null): void
    {
        $activity = (new Activity())
            ->setDescription('Work')
            ->setHours($hours)
            ->setWorkDate($workDate ?? new \DateTimeImmutable('today'))
            ->setTicket($ticket)
            ->setCreatedBy($this->admin)
            ->setOrganization($this->organization);
        $this->em->persist($activity);
        $this->em->flush();
    }
}
