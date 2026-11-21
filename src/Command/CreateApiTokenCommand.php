<?php

namespace App\Command;

use App\Entity\ApiToken;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:api-token:create',
    description: 'Create an API token (used by the MCP server) for a user',
)]
class CreateApiTokenCommand extends Command
{
    public function __construct(
        private UserRepository $userRepository,
        private EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email of the user the token acts as')
            ->addArgument('name', InputArgument::REQUIRED, 'Label to recognize the token, e.g. "claude-code-laptop"')
            ->addOption('scope', null, InputOption::VALUE_REQUIRED, sprintf('MCP server the token opens: %s (/mcp) or %s (/mcp/notes)', ApiToken::SCOPE_TMA, ApiToken::SCOPE_NOTES), ApiToken::SCOPE_TMA);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $user = $this->userRepository->findOneBy(['email' => $input->getArgument('email')]);
        if ($user === null) {
            $io->error('No user with this email.');

            return Command::FAILURE;
        }

        $scope = $input->getOption('scope');
        if (!\in_array($scope, ApiToken::SCOPES, true)) {
            $io->error(sprintf('Unknown scope "%s", expected one of: %s.', $scope, implode(', ', ApiToken::SCOPES)));

            return Command::FAILURE;
        }

        [$token, $plainToken] = ApiToken::generate($user, $input->getArgument('name'), $scope);
        $this->entityManager->persist($token);
        $this->entityManager->flush();

        $io->success(sprintf('Token #%d "%s" (%s) created for %s.', $token->getId(), $token->getName(), $scope, $user->getEmail()));
        $io->warning('Copy it now, it cannot be displayed again:');
        $output->writeln($plainToken);

        return Command::SUCCESS;
    }
}
