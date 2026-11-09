<?php

namespace App\Tests\Functional;

use App\Entity\Notification;
use App\Entity\Organization;
use App\Entity\Priority;
use App\Entity\Status;
use App\Entity\Ticket;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Regression tests for orphaned rows left behind when deleting tickets or users
 * (orphaned notifications used to break the navbar dropdown on every page).
 */
class DeletionIntegrityTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Organization $organization;
    private User $admin;
    private User $member;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        // Fresh schema for every test. Foreign keys are disabled while dropping,
        // otherwise SQLite refuses to drop tables still referenced by leftover rows.
        $connection = $this->em->getConnection();
        $schemaTool = new SchemaTool($this->em);
        $connection->executeStatement('PRAGMA foreign_keys = OFF');
        $schemaTool->dropDatabase();
        $schemaTool->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $connection->executeStatement('PRAGMA foreign_keys = ON');

        $this->organization = (new Organization())
            ->setName('Acme')
            ->setIsActive(true);
        $this->em->persist($this->organization);

        $this->admin = $this->createUser('admin@example.com', ['ROLE_ADMIN']);
        $this->member = $this->createUser('member@example.com', []);

        $this->em->flush();
    }

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

    /**
     * Fixture flushes render notification e-mails, which makes Twig cache its
     * globals (current organization, notifications) for an anonymous user.
     * Rebooting gives the HTTP requests a fresh container, like in production.
     */
    private function rebootKernel(): void
    {
        $kernel = $this->client->getKernel();
        $kernel->shutdown();
        $kernel->boot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    private function createUser(string $email, array $roles): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setRoles($roles)
            ->setPassword('not-used')
            ->setIsVerified(true)
            ->setOrganization($this->organization);
        $this->em->persist($user);

        return $user;
    }

    private function createTicket(User $author): Ticket
    {
        $status = (new Status())->setName('Open')->setSlug('open-'.uniqid())->setIsClosed(false)->setSortOrder(1);
        $priority = (new Priority())->setName('Normal')->setLevel(1);
        $ticket = (new Ticket())
            ->setTitle('Broken printer')
            ->setDescription('It does not print.')
            ->setOrganization($this->organization)
            ->setCreatedBy($author)
            ->setStatus($status)
            ->setPriority($priority);

        $this->em->persist($status);
        $this->em->persist($priority);
        $this->em->persist($ticket);
        $this->em->flush();

        return $ticket;
    }

    private function countNotificationsForTicket(int $ticketId): int
    {
        return (int) $this->em->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM notification WHERE ticket_id = ?', [$ticketId]);
    }
}
