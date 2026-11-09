<?php

namespace App\Tests\Functional;

use App\Entity\Notification;
use App\Entity\Ticket;
use App\Entity\User;

/**
 * Regression tests for orphaned rows left behind when deleting tickets or users
 * (orphaned notifications used to break the navbar dropdown on every page).
 */
class DeletionIntegrityTest extends FunctionalTestCase
{
    public function testDeletingTicketRemovesItsNotificationsAndPagesStillWork(): void
    {
        $ticket = $this->createTicket($this->member);

        $notification = (new Notification())
            ->setUser($this->admin)
            ->setTicket($ticket)
            ->setTriggeredBy($this->member)
            ->setType('ticket_created');
        $this->em->persist($notification);
        $this->em->flush();

        $ticketId = $ticket->getId();
        $this->assertGreaterThan(0, $this->countNotificationsForTicket($ticketId));

        $this->rebootKernel();
        $this->client->loginUser($this->admin);
        $slug = $this->organization->getSlug();

        // Delete through the real form so CSRF and controller logic are exercised
        $crawler = $this->client->request('GET', sprintf('/%s/tickets/%d', $slug, $ticketId));
        $this->assertResponseIsSuccessful();
        $form = $crawler->filter(sprintf('form[action$="/tickets/%d/delete"]', $ticketId))->form();
        $this->client->submit($form);
        $this->assertResponseRedirects(sprintf('/%s/tickets', $slug));

        $this->em->clear();
        $this->assertNull($this->em->getRepository(Ticket::class)->find($ticketId));
        $this->assertSame(0, $this->countNotificationsForTicket($ticketId));

        // The navbar notification dropdown is rendered on this page
        $this->client->request('GET', sprintf('/%s/tickets', $slug));
        $this->assertResponseStatusCodeSame(200);
    }

    public function testDatabaseCascadesNotificationsWhenTicketRowIsDeleted(): void
    {
        $ticket = $this->createTicket($this->member);
        $this->em->persist(
            (new Notification())->setUser($this->admin)->setTicket($ticket)->setType('ticket_created')
        );
        $this->em->flush();
        $ticketId = $ticket->getId();

        // Bypass the ORM: only the SQLite foreign key (ON DELETE CASCADE) can clean up here
        $this->em->getConnection()->executeStatement('DELETE FROM ticket WHERE id = ?', [$ticketId]);

        $this->assertSame(0, $this->countNotificationsForTicket($ticketId));
    }

    public function testDeletingUserWhoOwnsTicketsIsRefused(): void
    {
        $this->createTicket($this->member);
        $memberId = $this->member->getId();

        $this->rebootKernel();
        $this->client->loginUser($this->admin);
        $crawler = $this->client->request('GET', '/admin/users');
        $this->assertResponseIsSuccessful();
        $form = $crawler->filter(sprintf('form[action$="/admin/users/%d/delete"]', $memberId))->form();
        $this->client->submit($form);

        $this->assertResponseRedirects('/admin/users');
        $this->client->followRedirect();
        $this->assertSelectorExists('.alert-error, .alert-danger');

        $this->em->clear();
        $this->assertNotNull($this->em->getRepository(User::class)->find($memberId));
    }

    private function countNotificationsForTicket(int $ticketId): int
    {
        return (int) $this->em->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM notification WHERE ticket_id = ?', [$ticketId]);
    }
}
