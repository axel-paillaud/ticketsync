<?php

namespace App\Command;

use App\Repository\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:api-token:revoke',
    description: 'List API tokens, or revoke one by id',
)]
class RevokeApiTokenCommand extends Command
{
    public function __construct(
        private ApiTokenRepository $apiTokenRepository,
        private EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::OPTIONAL, 'Id of the token to revoke (omit to list tokens)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = $input->getArgument('id');

        if ($id === null) {
            $io->table(
                ['Id', 'Name', 'Owner', 'Created', 'Last used'],
                array_map(static fn ($token) => [
                    $token->getId(),
                    $token->getName(),
                    $token->getOwner()->getEmail(),
                    $token->getCreatedAt()->format('Y-m-d H:i'),
                    $token->getLastUsedAt()?->format('Y-m-d H:i') ?? 'never',
                ], $this->apiTokenRepository->findAll()),
            );

            return Command::SUCCESS;
        }

        $token = $this->apiTokenRepository->find($id);
        if ($token === null) {
            $io->error('No token with this id.');

            return Command::FAILURE;
        }

        $this->entityManager->remove($token);
        $this->entityManager->flush();
        $io->success(sprintf('Token "%s" revoked.', $token->getName()));

        return Command::SUCCESS;
    }
}
