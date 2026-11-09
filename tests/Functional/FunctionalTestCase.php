<?php

namespace App\Tests\Functional;

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
 * Shared setup for functional tests: fresh schema, one organization,
 * an admin and a regular member.
 */
abstract class FunctionalTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $em;
    protected Organization $organization;
    protected User $admin;
    protected User $member;

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

    /**
     * Fixture flushes render notification e-mails, which makes Twig cache its
     * globals (current organization, notifications) for an anonymous user.
     * Rebooting gives the HTTP requests a fresh container, like in production.
     */
    protected function rebootKernel(): void
    {
        $kernel = $this->client->getKernel();
        $kernel->shutdown();
        $kernel->boot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function createUser(string $email, array $roles): User
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

    protected function createStatus(string $slug, bool $isClosed): Status
    {
        $status = (new Status())
            ->setName(ucfirst($slug))
            ->setSlug($slug)
            ->setIsClosed($isClosed)
            ->setSortOrder(1);
        $this->em->persist($status);

        return $status;
    }

    protected function createTicket(User $author, ?Status $status = null): Ticket
    {
        $status ??= $this->createStatus('open-'.uniqid(), false);
        $priority = (new Priority())->setName('Normal')->setLevel(1);
        $ticket = (new Ticket())
            ->setTitle('Broken printer')
            ->setDescription('It does not print.')
            ->setOrganization($this->organization)
            ->setCreatedBy($author)
            ->setStatus($status)
            ->setPriority($priority);

        $this->em->persist($priority);
        $this->em->persist($ticket);
        $this->em->flush();

        return $ticket;
    }
}
