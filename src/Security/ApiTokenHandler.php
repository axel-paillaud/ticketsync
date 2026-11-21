<?php

namespace App\Security;

use App\Entity\ApiToken;
use App\Repository\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Resolves the "Authorization: Bearer <token>" header of an MCP firewall
 * to the user who owns the token.
 *
 * One instance per MCP server (see services.yaml): a token is only accepted
 * by the server of its scope.
 */
final class ApiTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private ApiTokenRepository $apiTokenRepository,
        private EntityManagerInterface $entityManager,
        private string $scope,
    ) {}

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $token = $this->apiTokenRepository->findOneByPlainToken($accessToken);

        // Same message as an unknown token: do not tell which server it belongs to.
        if ($token === null || $token->getScope() !== $this->scope) {
            throw new BadCredentialsException('Invalid API token.');
        }

        $token->markUsed();
        $this->entityManager->flush();

        return new UserBadge($token->getOwner()->getUserIdentifier());
    }
}
