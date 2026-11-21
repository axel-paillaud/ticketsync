<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * 401 responses of the MCP firewall (missing or invalid token).
 *
 * The WWW-Authenticate header points to the protected resource metadata
 * (RFC 9728): that is how OAuth clients such as claude.ai discover where
 * to send the user to log in.
 */
final class McpUnauthorizedResponder implements AuthenticationEntryPointInterface, AuthenticationFailureHandlerInterface
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->unauthorized($request, null);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return $this->unauthorized($request, 'invalid_token');
    }

    private function unauthorized(Request $request, ?string $error): Response
    {
        // Each MCP server is its own protected resource, with its own metadata.
        $route = str_starts_with($request->getPathInfo(), '/mcp/notes')
            ? 'oauth_protected_resource_metadata_mcp_notes'
            : 'oauth_protected_resource_metadata_mcp';
        $metadataUrl = $this->urlGenerator->generate($route, [], UrlGeneratorInterface::ABSOLUTE_URL);

        $challenge = sprintf('Bearer resource_metadata="%s"', $metadataUrl);
        if ($error !== null) {
            $challenge .= sprintf(', error="%s"', $error);
        }

        return new JsonResponse(
            ['error' => $error ?? 'unauthorized'],
            Response::HTTP_UNAUTHORIZED,
            ['WWW-Authenticate' => $challenge],
        );
    }
}
