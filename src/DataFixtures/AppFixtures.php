<?php

namespace App\DataFixtures;

use App\Entity\Ticket;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // $product = new Product();
        // $manager->persist($product);

        $ticket = new Ticket();

        $manager->flush();
    }
}
