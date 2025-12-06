<?php

namespace App\DataFixtures;

use App\Entity\Comment;
use App\Entity\Ticket;
use App\Entity\Organization;
use App\Entity\Priority;
use App\Entity\Status;
use App\Entity\User;
use Faker\Factory;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

class AppFixtures extends Fixture
{
    public function __construct(
        private UserPasswordHasherInterface $passwordHasher
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create('fr_FR');

        // CREATE STATUS
        $statusOpen = new Status();
        $statusOpen->setName('Open');
        $statusOpen->setSlug('open');
        $statusOpen->setIsClosed(false);
        $statusOpen->setSortOrder(1);
        $manager->persist($statusOpen);

        $statusInProgress = new Status();
        $statusInProgress->setName('In Progress');
        $statusInProgress->setSlug('in-progress');
        $statusInProgress->setIsClosed(false);
        $statusInProgress->setSortOrder(2);
        $manager->persist($statusInProgress);

        $statusResolved = new Status();
        $statusResolved->setName('Resolved');
        $statusResolved->setSlug('resolved');
        $statusResolved->setIsClosed(true);
        $statusResolved->setSortOrder(3);
        $manager->persist($statusResolved);

        $statusClosed = new Status();
        $statusClosed->setName('Closed');
        $statusClosed->setSlug('closed');
        $statusClosed->setIsClosed(true);
        $statusClosed->setSortOrder(4);
        $manager->persist($statusClosed);

        $statuses = [$statusOpen, $statusInProgress, $statusResolved, $statusClosed];

        // CREATE PRIORITY
        $priorityA = new Priority();
        $priorityA->setName('Priority A');
        $priorityA->setLevel(3);
        $priorityA->setColor('#ff0000');
        $manager->persist($priorityA);

        $priorityB = new Priority();
        $priorityB->setName('Priority B');
        $priorityB->setLevel(2);
        $priorityB->setColor('#ff9900');
        $manager->persist($priorityB);

        $priorityC = new Priority();
        $priorityC->setName('Priority C');
        $priorityC->setLevel(1);
        $priorityC->setColor('#999999');
        $manager->persist($priorityC);

        $priorities = [$priorityA, $priorityB, $priorityC];

        $slugger = new AsciiSlugger();

        $users = [];
        $organizations = [];

        // CREATE ORGANIZATION
        for ($i = 0; $i < 3; $i++) {
            $org = new Organization();
            $companyName = $faker->company();
            $org->setName($companyName);
            $org->setIsActive(true);
            $org->setEmail($faker->companyEmail());
            $org->setPhone($faker->phoneNumber());
            $org->setAddress($faker->address());

            $manager->persist($org);
            $organizations[] = $org;

            // Create 2 users per organization
            for ($j = 0; $j < 2; $j++) {
                $user = new User();
                $user->setEmail($faker->unique()->email());
                $user->setPassword($this->passwordHasher->hashPassword($user, 'password'));
                $user->setOrganization($org);
                $user->setRoles($j === 0 ? ['ROLE_ADMIN'] : ['ROLE_USER']);

                $manager->persist($user);
                $users[] = $user;
            }
        }

        // CREATE 20 TICKETS
        for ($i = 0; $i < 20; $i++) {
            $ticket = new Ticket();

            // Choose random user
            $createdBy = $faker->randomElement($users);

            $ticket->setTitle($faker->sentence(6));
            $ticket->setDescription($faker->paragraphs(3, true));
            $ticket->setOrganization($createdBy->getOrganization());
            $ticket->setCreatedBy($createdBy);
            $ticket->setStatus($faker->randomElement($statuses));
            $ticket->setPriority($faker->randomElement($priorities));

            // 50% tickets are assigned
            if ($faker->boolean()) {
                // Assigned to a user of same organization
                $orgUsers = array_filter($users, fn($u) => $u->getOrganization() === $createdBy->getOrganization());
                $ticket->setAssignedTo($faker->randomElement($orgUsers));
            }

            $manager->persist($ticket);
        }

        $manager->flush();

        // CREATE COMMENTS
        foreach ($manager->getRepository(Ticket::class)->findAll() as $ticket) {
            $numComments = $faker->numberBetween(2, 6);

            for ($i = 0; $i < $numComments; $i++) {
                $comment = new Comment();
                $comment->setContent($faker->paragraphs($faker->numberBetween(1, 3), true));
                $comment->setTicket($ticket);

                $orgUsers = array_filter($users, fn($u) => $u->getOrganization() === $ticket->getOrganization());
                $comment->setAuthor($faker->randomElement($orgUsers));

                $manager->persist($comment);
            }
        };

        $manager->flush();
    }
}
