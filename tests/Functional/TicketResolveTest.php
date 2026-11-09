<?php

namespace App\Tests\Functional;

use App\Entity\Ticket;

/**
 * The delete modal offers to resolve the ticket instead of deleting it.
 */
class TicketResolveTest extends FunctionalTestCase
{
    public function testMemberCanResolveTicketFromDeleteModal(): void
    {
        $this->createStatus('resolved', true);
        $ticket = $this->createTicket($this->member);
        $ticketId = $ticket->getId();
        $slug = $this->organization->getSlug();

        $this->rebootKernel();
        $this->client->loginUser($this->member);

        $crawler = $this->client->request('GET', sprintf('/%s/tickets/%d', $slug, $ticketId));
        $this->assertResponseIsSuccessful();
        $form = $crawler->filter(sprintf('#deleteModal form[action$="/tickets/%d/resolve"]', $ticketId))->form();
        $this->client->submit($form);

        $this->assertResponseRedirects(sprintf('/%s/tickets/%d', $slug, $ticketId));
        $this->client->followRedirect();
        $this->assertSelectorExists('.alert-success');

        $this->em->clear();
        $ticket = $this->em->getRepository(Ticket::class)->find($ticketId);
        $this->assertNotNull($ticket, 'Resolving must not delete the ticket.');
        $this->assertSame('resolved', $ticket->getStatus()->getSlug());
    }

    public function testClosedTicketOnlyOffersDeletion(): void
    {
        $ticket = $this->createTicket($this->member, $this->createStatus('resolved', true));
        $ticketId = $ticket->getId();

        $this->rebootKernel();
        $this->client->loginUser($this->member);

        $this->client->request('GET', sprintf('/%s/tickets/%d', $this->organization->getSlug(), $ticketId));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists(sprintf('#deleteModal form[action$="/tickets/%d/resolve"]', $ticketId));
        $this->assertSelectorExists(sprintf('#deleteModal form[action$="/tickets/%d/delete"]', $ticketId));
        $this->assertSelectorNotExists('[data-bs-target="#resolveModal"]');
        $this->assertSelectorNotExists('#resolveModal');
    }

    public function testMemberCanResolveTicketFromShowPageButton(): void
    {
        $this->createStatus('resolved', true);
        $ticket = $this->createTicket($this->member);
        $ticketId = $ticket->getId();
        $slug = $this->organization->getSlug();

        $this->rebootKernel();
        $this->client->loginUser($this->member);

        $crawler = $this->client->request('GET', sprintf('/%s/tickets/%d', $slug, $ticketId));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-bs-target="#resolveModal"]');
        $form = $crawler->filter(sprintf('#resolveModal form[action$="/tickets/%d/resolve"]', $ticketId))->form();
        $this->client->submit($form);

        $this->assertResponseRedirects(sprintf('/%s/tickets/%d', $slug, $ticketId));
        $this->em->clear();
        $this->assertSame('resolved', $this->em->getRepository(Ticket::class)->find($ticketId)->getStatus()->getSlug());
    }

    public function testResolveRequiresValidCsrfToken(): void
    {
        $this->createStatus('resolved', true);
        $ticket = $this->createTicket($this->member);
        $ticketId = $ticket->getId();

        $this->rebootKernel();
        $this->client->loginUser($this->member);
        $this->client->request('POST', sprintf('/%s/tickets/%d/resolve', $this->organization->getSlug(), $ticketId), [
            '_token' => 'invalid',
        ]);

        $this->em->clear();
        $this->assertFalse($this->em->getRepository(Ticket::class)->find($ticketId)->getStatus()->isClosed());
    }
}
