<?php

namespace App\DataFixtures;

use App\Entity\Comment;
use App\Entity\Organization;
use App\Entity\Priority;
use App\Entity\Status;
use App\Entity\Ticket;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Development fixtures - Test data for development environment
 * Should NOT be loaded in production
 */
class DevFixtures extends Fixture implements FixtureGroupInterface, DependentFixtureInterface
{
    public function __construct(
        private UserPasswordHasherInterface $passwordHasher
    ) {
    }

    public static function getGroups(): array
    {
        return ['dev'];
    }

    public function getDependencies(): array
    {
        return [
            ProductionFixtures::class,
        ];
    }

    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create('fr_FR');

        // Get existing statuses and priorities from ProductionFixtures
        $statuses = $manager->getRepository(Status::class)->findAll();
        $priorities = $manager->getRepository(Priority::class)->findAll();

        $users = [];
        $organizations = [];

        // CREATE ORGANIZATIONS
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
                $user->setFirstName($faker->firstName());
                $user->setLastName($faker->lastName());

                $manager->persist($user);
                $users[] = $user;
            }
        }

        $manager->flush();

        // CREATE TICKETS
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
        }

        $manager->flush();
    }
}
