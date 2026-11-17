<?php

namespace App\Security;

use App\Repository\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Resolves the "Authorization: Bearer <token>" header of the MCP firewall
 * to the user who owns the token.
 */
final class ApiTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private ApiTokenRepository $apiTokenRepository,
        private EntityManagerInterface $entityManager,
    ) {}

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $token = $this->apiTokenRepository->findOneByPlainToken($accessToken);

        if ($token === null) {
            throw new BadCredentialsException('Invalid API token.');
        }

        $token->markUsed();
        $this->entityManager->flush();

        return new UserBadge($token->getOwner()->getUserIdentifier());
    }
}
