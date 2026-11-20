<?php

namespace App\Tests\Functional;

use App\Entity\Activity;
use App\Entity\Comment;
use App\Entity\Ticket;

/**
 * Members may only delete a ticket that has not been worked on yet
 * (no comments, no activities). Admins can always delete.
 */
class TicketDeletePermissionTest extends FunctionalTestCase
{
    public function testMemberCanDeleteEmptyTicket(): void
    {
        $ticket = $this->createTicket($this->member);
        $ticketId = $ticket->getId();
        $slug = $this->organization->getSlug();

        $this->rebootKernel();
        $this->client->loginUser($this->member);

        $crawler = $this->client->request('GET', sprintf('/%s/tickets/%d', $slug, $ticketId));
        $this->assertSelectorExists('[data-bs-target="#deleteModal"]');
        $form = $crawler->filter(sprintf('#deleteModal form[action$="/tickets/%d/delete"]', $ticketId))->form();
        $this->client->submit($form);

        $this->assertResponseRedirects(sprintf('/%s/tickets', $slug));
        $this->em->clear();
        $this->assertNull($this->em->getRepository(Ticket::class)->find($ticketId));
    }

    public function testMemberCannotDeleteTicketWithComment(): void
    {
        $ticket = $this->createTicket($this->member);
        $this->addComment($ticket);

        $this->assertMemberCannotDelete($ticket->getId());
    }

    public function testMemberCannotDeleteTicketWithActivity(): void
    {
        $ticket = $this->createTicket($this->member);
        $this->addActivity($ticket);

        $this->assertMemberCannotDelete($ticket->getId());
    }

    public function testAdminCanDeleteUsedTicket(): void
    {
        $ticket = $this->createTicket($this->member);
        $this->addComment($ticket);
        $this->addActivity($ticket);
        $ticketId = $ticket->getId();
        $slug = $this->organization->getSlug();

        $this->rebootKernel();
        $this->client->loginUser($this->admin);

        $crawler = $this->client->request('GET', sprintf('/%s/tickets/%d', $slug, $ticketId));
        $form = $crawler->filter(sprintf('#deleteModal form[action$="/tickets/%d/delete"]', $ticketId))->form();
        $this->client->submit($form);

        $this->assertResponseRedirects(sprintf('/%s/tickets', $slug));
        $this->em->clear();
        $this->assertNull($this->em->getRepository(Ticket::class)->find($ticketId));
    }

    private function assertMemberCannotDelete(int $ticketId): void
    {
        $slug = $this->organization->getSlug();

        $this->rebootKernel();
        $this->client->loginUser($this->member);

        // Delete hidden on show and edit pages, resolve and edit still available
        $this->client->request('GET', sprintf('/%s/tickets/%d', $slug, $ticketId));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-bs-target="#deleteModal"]');
        $this->assertSelectorNotExists('#deleteModal');
        $this->assertSelectorExists('[data-bs-target="#resolveModal"]');
        $this->assertSelectorExists(sprintf('a[href$="/tickets/%d/edit"]', $ticketId));

        $this->client->request('GET', sprintf('/%s/tickets/%d/edit', $slug, $ticketId));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-bs-target="#deleteModal"]');

        // A direct POST is refused by the voter
        $this->client->request('POST', sprintf('/%s/tickets/%d/delete', $slug, $ticketId), ['_token' => 'whatever']);
        $this->assertResponseStatusCodeSame(403);

        $this->em->clear();
        $this->assertNotNull($this->em->getRepository(Ticket::class)->find($ticketId));
    }

    private function addComment(Ticket $ticket): void
    {
        $comment = (new Comment())
            ->setContent('Looking into it.')
            ->setAuthor($this->admin);
        $ticket->addComment($comment);
        $this->em->persist($comment);
        $this->em->flush();
    }

    private function addActivity(Ticket $ticket): void
    {
        $activity = (new Activity())
            ->setDescription('Diagnosis')
            ->setHours('0.50')
            ->setWorkDate(new \DateTimeImmutable('2026-11-20'))
            ->setCreatedBy($this->admin)
            ->setOrganization($this->organization);
        $ticket->addActivity($activity);
        $this->em->persist($activity);
        $this->em->flush();
    }
}
