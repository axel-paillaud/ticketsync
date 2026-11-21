<?php

namespace App\Controller;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Repository\UserRepository;
use App\Security\OAuth\AuthorizationCodeStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Minimal OAuth 2.1 authorization server so that claude.ai (custom connectors)
 * can use the MCP servers: authorization code + PKCE (S256).
 *
 * One confidential client per MCP server, i.e. per claude.ai connector:
 * OAUTH_CLIENT_ID / OAUTH_CLIENT_SECRET for TicketSync (/mcp), and
 * OAUTH_NOTES_CLIENT_ID / OAUTH_NOTES_CLIENT_SECRET for the notes (/mcp/notes).
 * The client decides the scope of the token, so a connector never gets
 * access to the other server.
 *
 * The access token handed out is a regular ApiToken, so the MCP firewalls
 * accept it without any change and it can be revoked with app:api-token:revoke.
 */
class OAuthController extends AbstractController
{
    public const TOKEN_NAME = 'claude.ai (OAuth)';
    public const NOTES_TOKEN_NAME = 'claude.ai notes (OAuth)';

    /** MCP endpoint (protected resource) of each token scope. */
    private const RESOURCE_PATHS = [
        ApiToken::SCOPE_TMA => '/mcp',
        ApiToken::SCOPE_NOTES => '/mcp/notes',
    ];

    /** @var array<string, array{id: string, secret: string}> scope => client */
    private array $clients;

    /**
     * @param list<string> $redirectUris
     */
    public function __construct(
        private AuthorizationCodeStore $codeStore,
        private UserRepository $userRepository,
        private ApiTokenRepository $apiTokenRepository,
        private EntityManagerInterface $entityManager,
        #[Autowire(env: 'OAUTH_CLIENT_ID')]
        string $clientId,
        #[Autowire(env: 'OAUTH_CLIENT_SECRET')]
        string $clientSecret,
        #[Autowire(env: 'OAUTH_NOTES_CLIENT_ID')]
        string $notesClientId,
        #[Autowire(env: 'OAUTH_NOTES_CLIENT_SECRET')]
        string $notesClientSecret,
        #[Autowire(param: 'app.oauth.redirect_uris')]
        private array $redirectUris,
    ) {
        $this->clients = [
            ApiToken::SCOPE_TMA => ['id' => $clientId, 'secret' => $clientSecret],
            ApiToken::SCOPE_NOTES => ['id' => $notesClientId, 'secret' => $notesClientSecret],
        ];
        if ($clientId !== '' && $clientId === $notesClientId) {
            throw new \LogicException('OAUTH_CLIENT_ID and OAUTH_NOTES_CLIENT_ID must be different.');
        }
    }

    /**
     * Protected resource metadata (RFC 9728), linked from the 401 of /mcp.
     */
    #[Route('/.well-known/oauth-protected-resource', name: 'oauth_protected_resource_metadata', methods: ['GET'])]
    #[Route('/.well-known/oauth-protected-resource/mcp', name: 'oauth_protected_resource_metadata_mcp', methods: ['GET'])]
    public function protectedResourceMetadata(Request $request): JsonResponse
    {
        return $this->resourceMetadata($request, ApiToken::SCOPE_TMA);
    }

    #[Route('/.well-known/oauth-protected-resource/mcp/notes', name: 'oauth_protected_resource_metadata_mcp_notes', methods: ['GET'])]
    public function notesProtectedResourceMetadata(Request $request): JsonResponse
    {
        return $this->resourceMetadata($request, ApiToken::SCOPE_NOTES);
    }

    private function resourceMetadata(Request $request, string $scope): JsonResponse
    {
        return new JsonResponse([
            'resource' => $request->getSchemeAndHttpHost().self::RESOURCE_PATHS[$scope],
            'authorization_servers' => [$request->getSchemeAndHttpHost()],
            'bearer_methods_supported' => ['header'],
        ]);
    }

    /**
     * Authorization server metadata (RFC 8414).
     */
    #[Route('/.well-known/oauth-authorization-server', name: 'oauth_authorization_server_metadata', methods: ['GET'])]
    public function authorizationServerMetadata(Request $request): JsonResponse
    {
        return new JsonResponse([
            'issuer' => $request->getSchemeAndHttpHost(),
            'authorization_endpoint' => $this->generateUrl('oauth_authorize', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'token_endpoint' => $this->generateUrl('oauth_token', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic'],
        ]);
    }

    /**
     * Consent page (behind the regular login), then redirect back to the
     * client with a one-time code.
     */
    #[Route('/oauth/authorize', name: 'oauth_authorize', methods: ['GET', 'POST'])]
    public function authorize(Request $request): Response
    {
        $params = $request->isMethod('POST') ? $request->request : $request->query;
        $clientId = $params->getString('client_id');
        $redirectUri = $params->getString('redirect_uri');
        $state = $params->getString('state');

        // Never redirect to an unchecked URI: show the error here instead.
        $scope = $this->scopeOfClient($clientId);
        if ($scope === null) {
            return $this->authorizeError('Unknown OAuth client.');
        }
        if (!\in_array($redirectUri, $this->redirectUris, true)) {
            return $this->authorizeError('This redirect URI is not allowed.');
        }

        if ($params->getString('response_type') !== 'code') {
            return $this->redirectWithError($redirectUri, 'unsupported_response_type', $state);
        }
        $codeChallenge = $params->getString('code_challenge');
        if ($codeChallenge === '' || $params->getString('code_challenge_method') !== 'S256') {
            return $this->redirectWithError($redirectUri, 'invalid_request', $state, 'PKCE with S256 is required.');
        }
        // RFC 8707: a client may only ask for the MCP server it belongs to.
        $resource = $params->getString('resource');
        if ($resource !== '' && rtrim($resource, '/') !== $request->getSchemeAndHttpHost().self::RESOURCE_PATHS[$scope]) {
            return $this->redirectWithError($redirectUri, 'invalid_target', $state, 'This client cannot access this resource.');
        }

        /** @var User $user */
        $user = $this->getUser();
        if (!$this->isGranted('ROLE_ADMIN')) {
            return $this->authorizeError('Only administrators can connect an assistant to TicketSync.');
        }

        if ($request->isMethod('GET')) {
            return $this->render('oauth/authorize.html.twig', [
                'params' => [
                    'response_type' => 'code',
                    'client_id' => $clientId,
                    'redirect_uri' => $redirectUri,
                    'code_challenge' => $codeChallenge,
                    'code_challenge_method' => 'S256',
                    'state' => $state,
                    'resource' => $resource,
                ],
                'scope' => $scope,
                'redirect_host' => parse_url($redirectUri, \PHP_URL_HOST),
            ]);
        }

        if (!$this->isCsrfTokenValid('oauth_authorize', $request->request->getString('_token'))) {
            return $this->authorizeError('Invalid security token, please try again.');
        }
        if ($request->request->getString('decision') !== 'allow') {
            return $this->redirectWithError($redirectUri, 'access_denied', $state);
        }

        $code = $this->codeStore->issue($user->getId(), $clientId, $redirectUri, $codeChallenge);

        return $this->redirectToClient($redirectUri, ['code' => $code, 'state' => $state]);
    }

    /**
     * Exchanges the code for an access token (called server to server).
     */
    #[Route('/oauth/token', name: 'oauth_token', methods: ['POST'])]
    public function token(Request $request): JsonResponse
    {
        [$clientId, $clientSecret] = $this->clientCredentials($request);
        $scope = $this->scopeOfClient($clientId);
        if ($scope === null || !hash_equals($this->clients[$scope]['secret'], $clientSecret)) {
            return $this->tokenError('invalid_client', Response::HTTP_UNAUTHORIZED);
        }

        if ($request->request->getString('grant_type') !== 'authorization_code') {
            return $this->tokenError('unsupported_grant_type');
        }

        $data = $this->codeStore->consume($request->request->getString('code'));
        // A code is bound to the client it was issued to.
        if ($data === null
            || !hash_equals($data['clientId'], $clientId)
            || $data['redirectUri'] !== $request->request->getString('redirect_uri')) {
            return $this->tokenError('invalid_grant');
        }

        $verifier = $request->request->getString('code_verifier');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        if ($verifier === '' || !hash_equals($data['codeChallenge'], $challenge)) {
            return $this->tokenError('invalid_grant');
        }

        $user = $this->userRepository->find($data['userId']);
        if ($user === null || !\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return $this->tokenError('invalid_grant');
        }

        // One token per user for this client: reconnecting replaces the old one.
        $tokenName = $scope === ApiToken::SCOPE_NOTES ? self::NOTES_TOKEN_NAME : self::TOKEN_NAME;
        foreach ($this->apiTokenRepository->findBy(['owner' => $user, 'name' => $tokenName, 'scope' => $scope]) as $old) {
            $this->entityManager->remove($old);
        }
        [$token, $plainToken] = ApiToken::generate($user, $tokenName, $scope);
        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return new JsonResponse(
            ['access_token' => $plainToken, 'token_type' => 'Bearer'],
            headers: ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache'],
        );
    }

    /**
     * Scope of the configured client with this id, or null if there is none
     * (a client without id or secret is disabled).
     */
    private function scopeOfClient(string $clientId): ?string
    {
        foreach ($this->clients as $scope => $client) {
            if ($client['id'] !== '' && $client['secret'] !== '' && hash_equals($client['id'], $clientId)) {
                return $scope;
            }
        }

        return null;
    }

    /**
     * client_secret_basic or client_secret_post.
     *
     * @return array{0: string, 1: string}
     */
    private function clientCredentials(Request $request): array
    {
        $header = $request->headers->get('Authorization', '');
        if (str_starts_with($header, 'Basic ')) {
            $decoded = base64_decode(substr($header, 6), true) ?: '';
            [$id, $secret] = array_pad(explode(':', $decoded, 2), 2, '');

            return [urldecode($id), urldecode($secret)];
        }

        return [$request->request->getString('client_id'), $request->request->getString('client_secret')];
    }

    private function authorizeError(string $message): Response
    {
        return $this->render('oauth/error.html.twig', ['message' => $message], new Response(status: Response::HTTP_BAD_REQUEST));
    }

    private function redirectWithError(string $redirectUri, string $error, string $state, ?string $description = null): RedirectResponse
    {
        return $this->redirectToClient($redirectUri, array_filter([
            'error' => $error,
            'error_description' => $description,
            'state' => $state,
        ], fn ($value) => $value !== null && $value !== ''));
    }

    /**
     * @param array<string, string> $query
     */
    private function redirectToClient(string $redirectUri, array $query): RedirectResponse
    {
        $query = array_filter($query, fn ($value) => $value !== '');
        $separator = str_contains($redirectUri, '?') ? '&' : '?';

        return new RedirectResponse($redirectUri.$separator.http_build_query($query));
    }

    private function tokenError(string $error, int $status = Response::HTTP_BAD_REQUEST): JsonResponse
    {
        return new JsonResponse(['error' => $error], $status, ['Cache-Control' => 'no-store']);
    }
}
