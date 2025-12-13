<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\OrganizationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-admin',
    description: 'Create an admin user',
)]
class CreateAdminCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private UserRepository $userRepository,
        private OrganizationRepository $organizationRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Admin email address')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = $input->getArgument('email');

        // Check if user already exists
        $existingUser = $this->userRepository->findOneBy(['email' => $email]);
        if ($existingUser) {
            $io->error(sprintf('A user with email "%s" already exists.', $email));
            return Command::FAILURE;
        }

        $helper = $this->getHelper('question');

        // Handle organization selection or creation
        $organization = $this->selectOrCreateOrganization($input, $output, $io, $helper);

        if ($organization === null) {
            $io->error('Cannot create admin user without an organization.');
            return Command::FAILURE;
        }

        // Ask for password (hidden input)
        $passwordQuestion = new Question('Password: ');
        $passwordQuestion->setHidden(true);
        $passwordQuestion->setHiddenFallback(false);
        $passwordQuestion->setValidator(function ($value) {
            if (empty($value)) {
                throw new \RuntimeException('Password cannot be empty.');
            }
            if (strlen($value) < 6) {
                throw new \RuntimeException('Password must be at least 6 characters long.');
            }
            return $value;
        });
        $password = $helper->ask($input, $output, $passwordQuestion);

        // Ask for password confirmation
        $confirmPasswordQuestion = new Question('Confirm password: ');
        $confirmPasswordQuestion->setHidden(true);
        $confirmPasswordQuestion->setHiddenFallback(false);
        $confirmPassword = $helper->ask($input, $output, $confirmPasswordQuestion);

        if ($password !== $confirmPassword) {
            $io->error('Passwords do not match.');
            return Command::FAILURE;
        }

        // Ask for first name (optional)
        $firstNameQuestion = new Question('First name (optional): ');
        $firstName = $helper->ask($input, $output, $firstNameQuestion);

        // Ask for last name (optional)
        $lastNameQuestion = new Question('Last name (optional): ');
        $lastName = $helper->ask($input, $output, $lastNameQuestion);

        // Create the admin user
        $user = new User();
        $user->setEmail($email);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->setRoles(['ROLE_ADMIN']);
        $user->setIsVerified(true);
        $user->setOrganization($organization);

        if ($firstName) {
            $user->setFirstName($firstName);
        }
        if ($lastName) {
            $user->setLastName($lastName);
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf('Admin user "%s" created successfully!', $email));
        $io->info([
            'Email: ' . $user->getEmail(),
            'Name: ' . $user->getName(),
            'Organization: ' . $organization->getName(),
            'Roles: ROLE_ADMIN',
        ]);

        return Command::SUCCESS;
    }

    private function selectOrCreateOrganization(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        $helper
    ): ?\App\Entity\Organization {
        $organizations = $this->organizationRepository->findAll();

        // Build choices
        $organizationChoices = [];
        $createNewKey = 'CREATE_NEW';

        if (!empty($organizations)) {
            foreach ($organizations as $org) {
                $organizationChoices[$org->getId()] = sprintf('%s (slug: %s)', $org->getName(), $org->getSlug());
            }
        }

        // Always add "Create new organization" option
        $organizationChoices[$createNewKey] = '+ Create new organization';

        $organizationQuestion = new ChoiceQuestion(
            'Select an organization:',
            $organizationChoices,
            empty($organizations) ? $createNewKey : 0
        );
        $selectedOrgName = $helper->ask($input, $output, $organizationQuestion);

        // Check if user chose to create new organization
        // (handles both typing "CREATE_NEW" or selecting the option)
        if ($selectedOrgName === $createNewKey || $selectedOrgName === '+ Create new organization') {
            return $this->createNewOrganization($input, $output, $io, $helper);
        }

        // Find the selected organization
        $selectedKey = array_search($selectedOrgName, $organizationChoices);

        // Return the selected organization
        return $this->organizationRepository->find($selectedKey);
    }

    private function createNewOrganization(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        $helper
    ): \App\Entity\Organization {
        // Create a new organization
        $orgNameQuestion = new Question('Organization name: ');
        $orgNameQuestion->setValidator(function ($value) {
            if (empty($value)) {
                throw new \RuntimeException('Organization name cannot be empty.');
            }
            return $value;
        });
        $orgName = $helper->ask($input, $output, $orgNameQuestion);

        $orgEmailQuestion = new Question('Organization email (optional): ');
        $orgEmail = $helper->ask($input, $output, $orgEmailQuestion);

        $orgPhoneQuestion = new Question('Organization phone (optional): ');
        $orgPhone = $helper->ask($input, $output, $orgPhoneQuestion);

        $orgSiretQuestion = new Question('Organization SIRET (optional): ');
        $orgSiret = $helper->ask($input, $output, $orgSiretQuestion);

        $organization = new \App\Entity\Organization();
        $organization->setName($orgName);
        $organization->setIsActive(true);

        if ($orgEmail) {
            $organization->setEmail($orgEmail);
        }
        if ($orgPhone) {
            $organization->setPhone($orgPhone);
        }
        if ($orgSiret) {
            $organization->setSiret($orgSiret);
        }

        $this->entityManager->persist($organization);
        $this->entityManager->flush();

        $io->success(sprintf('Organization "%s" created successfully!', $orgName));

        return $organization;
    }
}
