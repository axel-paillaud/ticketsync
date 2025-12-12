<?php

namespace App\DataFixtures;

use App\Entity\Priority;
use App\Entity\Status;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Production fixtures - Essential data required for the app to work
 * These fixtures should be loaded in production
 */
class ProductionFixtures extends Fixture implements FixtureGroupInterface
{
    public static function getGroups(): array
    {
        return ['prod', 'dev'];
    }

    public function load(ObjectManager $manager): void
    {
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

        $statusWait = new Status();
        $statusWait->setName('Wait');
        $statusWait->setSlug('wait');
        $statusWait->setIsClosed(false);
        $statusWait->setSortOrder(3);
        $manager->persist($statusWait);

        $statusResolved = new Status();
        $statusResolved->setName('Resolved');
        $statusResolved->setSlug('resolved');
        $statusResolved->setIsClosed(true);
        $statusResolved->setSortOrder(4);
        $manager->persist($statusResolved);

        // CREATE PRIORITY
        $priorityA = new Priority();
        $priorityA->setName('Priority A');
        $priorityA->setLevel(3);
        $priorityA->setColor('#cc241d'); // Gruvbox red
        $manager->persist($priorityA);

        $priorityB = new Priority();
        $priorityB->setName('Priority B');
        $priorityB->setLevel(2);
        $priorityB->setColor('#d79921'); // Gruvbox yellow
        $manager->persist($priorityB);

        $priorityC = new Priority();
        $priorityC->setName('Priority C');
        $priorityC->setLevel(1);
        $priorityC->setColor('#928374'); // Gruvbox gray
        $manager->persist($priorityC);

        $manager->flush();
    }
}
