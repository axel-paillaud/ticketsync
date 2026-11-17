<?php

namespace App\Tests\Functional;

use App\Entity\Priority;
use App\Entity\Ticket;

/**
 * A title alone is enough to create a ticket.
 */
class TicketOptionalDescriptionTest extends FunctionalTestCase
{
    public function testMemberCanCreateTicketWithoutDescription(): void
    {
        $this->createStatus('open', false);
        $this->em->persist((new Priority())->setName('B')->setLevel(2));
        $this->em->flush();
        $slug = $this->organization->getSlug();

        $this->rebootKernel();
        $this->client->loginUser($this->member);

        $crawler = $this->client->request('GET', sprintf('/%s/tickets/new', $slug));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('textarea[name="ticket[description]"]:not([required])');

        $form = $crawler->filter('form[name="ticket"]')->form([
            'ticket[title]' => 'Title only',
            'ticket[description]' => '',
        ]);
        $this->client->submit($form);
        $this->assertResponseRedirects(sprintf('/%s/tickets', $slug));

        $this->em->clear();
        $ticket = $this->em->getRepository(Ticket::class)->findOneBy(['title' => 'Title only']);
        $this->assertNotNull($ticket);
        $this->assertNull($ticket->getDescription());

        // Both the list and the detail page render without a description block.
        $this->client->request('GET', sprintf('/%s/tickets', $slug));
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', sprintf('/%s/tickets/%d', $slug, $ticket->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('.description-section');
    }
}
