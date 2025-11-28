<?php

namespace App\DataFixtures;

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
        $slugger = new AsciiSlugger();

        // Récupérer les Status et Priority depuis la DB
        $statuses = $manager->getRepository(Status::class)->findAll();
        $priorities = $manager->getRepository(Priority::class)->findAll();

        $users = [];
        $organizations = [];

        for ($i = 0; $i < 3; $i++) {
            // Create organization
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

        // Create 20 tickets
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
    }
}
