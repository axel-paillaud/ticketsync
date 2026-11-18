<?php

namespace App\Tests\Functional;

use App\Entity\Organization;
use App\Entity\Priority;
use App\Entity\Ticket;

/**
 * Required ticket fields are validated server-side: an empty value must
 * re-display the form with an error, not crash on a database constraint.
 */
class TicketRequiredFieldsTest extends FunctionalTestCase
{
    private Organization $otherOrganization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createStatus('open', false);
        $this->em->persist((new Priority())->setName('B')->setLevel(2));
        $this->otherOrganization = (new Organization())->setName('Other client')->setIsActive(true);
        $this->em->persist($this->otherOrganization);
        $this->em->flush();
    }

    public function testAdminCanCreateTicketForAnotherOrganization(): void
    {
        $this->submitAsAdmin(['ticket[title]' => 'For the other client'], $this->otherOrganization->getId());

        $this->assertResponseRedirects('/admin');
        $ticket = $this->em->getRepository(Ticket::class)->findOneBy(['title' => 'For the other client']);
        $this->assertSame($this->otherOrganization->getId(), $ticket->getOrganization()->getId());
    }

    public function testAdminMustChooseAnOrganization(): void
    {
        $this->submitAsAdmin(['ticket[title]' => 'No organization'], '');

        $this->assertResponseStatusCodeSame(422);
        $this->assertAnySelectorTextContains('li', 'Please select an organization.');
        $this->assertNull($this->em->getRepository(Ticket::class)->findOneBy(['title' => 'No organization']));
    }

    public function testTitleIsRequired(): void
    {
        $this->submitAsAdmin(['ticket[title]' => ''], $this->organization->getId());

        $this->assertResponseStatusCodeSame(422);
        $this->assertAnySelectorTextContains('li', 'Please enter a title.');
        $this->assertSame(0, $this->em->getRepository(Ticket::class)->count([]));
    }

    public function testPriorityIsRequired(): void
    {
        $this->submitAsAdmin(['ticket[title]' => 'No priority', 'ticket[priority]' => ''], $this->organization->getId());

        $this->assertResponseStatusCodeSame(422);
        $this->assertAnySelectorTextContains('li', 'Please select a priority.');
        $this->assertSame(0, $this->em->getRepository(Ticket::class)->count([]));
    }

    /**
     * @param array<string, string> $values
     */
    private function submitAsAdmin(array $values, int|string $organizationId): void
    {
        $this->rebootKernel();
        $this->client->loginUser($this->admin);

        $crawler = $this->client->request('GET', sprintf('/%s/tickets/new', $this->organization->getSlug()));
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="ticket"]')->form();
        // Bypass browser-side "required": submit exactly what an altered request would send.
        $form['ticket[organization]']->disableValidation()->select((string) $organizationId);
        if (isset($values['ticket[priority]'])) {
            $form['ticket[priority]']->disableValidation()->select($values['ticket[priority]']);
            unset($values['ticket[priority]']);
        }
        $this->client->submit($form, $values);
        $this->em->clear();
    }
}
