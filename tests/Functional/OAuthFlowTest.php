<?php

namespace App\Tests\Functional;

use App\Controller\OAuthController;
use App\Entity\ApiToken;
use App\Entity\User;

/**
 * OAuth 2.1 flow used by claude.ai to reach the MCP server: discovery,
 * consent, code exchange with PKCE, then a call to /mcp with the token.
 * The test client is configured in .env.test.
 */
class OAuthFlowTest extends FunctionalTestCase
{
    private const REDIRECT_URI = 'https://claude.ai/api/mcp/auth_callback';
    private const VERIFIER = 'a-long-random-code-verifier-of-at-least-43-characters-0123456789';

    public function testMcpChallengePointsToTheMetadata(): void
    {
        $this->client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');

        $this->assertResponseStatusCodeSame(401);
        $this->assertSame(
            'Bearer resource_metadata="http://localhost/.well-known/oauth-protected-resource/mcp"',
            $this->client->getResponse()->headers->get('WWW-Authenticate'),
        );
    }

    public function testInvalidTokenChallengeAlsoPointsToTheMetadata(): void
    {
        $this->client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer tks_nope'], content: '{}');

        $this->assertResponseStatusCodeSame(401);
        $this->assertStringContainsString('error="invalid_token"', $this->client->getResponse()->headers->get('WWW-Authenticate'));
    }

    public function testDiscoveryDocuments(): void
    {
        $this->client->request('GET', '/.well-known/oauth-protected-resource/mcp');
        $this->assertResponseIsSuccessful();
        $resource = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('http://localhost/mcp', $resource['resource']);
        $this->assertSame(['http://localhost'], $resource['authorization_servers']);

        $this->client->request('GET', '/.well-known/oauth-authorization-server');
        $this->assertResponseIsSuccessful();
        $server = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('http://localhost/oauth/authorize', $server['authorization_endpoint']);
        $this->assertSame('http://localhost/oauth/token', $server['token_endpoint']);
        $this->assertSame(['S256'], $server['code_challenge_methods_supported']);
    }

    public function testAuthorizeRequiresLogin(): void
    {
        $this->client->request('GET', '/oauth/authorize?'.http_build_query($this->authorizeQuery()));

        $this->assertResponseRedirects('http://localhost/login');
    }

    public function testFullFlowGivesATokenAcceptedByMcp(): void
    {
        $code = $this->authorizeAs($this->admin);

        $token = $this->exchange($code);
        $this->assertResponseIsSuccessful();
        $this->assertSame('Bearer', $token['token_type']);
        $this->assertStringStartsWith(ApiToken::PREFIX, $token['access_token']);

        $this->client->request('POST', '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token['access_token'],
        ], content: json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'phpunit', 'version' => '1']],
        ]));
        $this->assertResponseIsSuccessful();
    }

    public function testCodeCanOnlyBeUsedOnce(): void
    {
        $code = $this->authorizeAs($this->admin);

        $this->exchange($code);
        $this->assertResponseIsSuccessful();

        $second = $this->exchange($code);
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('invalid_grant', $second['error']);
    }

    public function testWrongVerifierIsRejected(): void
    {
        $code = $this->authorizeAs($this->admin);

        $response = $this->exchange($code, ['code_verifier' => 'not-the-right-verifier-not-the-right-verifier-00']);

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('invalid_grant', $response['error']);
    }

    public function testWrongClientSecretIsRejected(): void
    {
        $code = $this->authorizeAs($this->admin);

        $response = $this->exchange($code, ['client_secret' => 'wrong']);

        $this->assertResponseStatusCodeSame(401);
        $this->assertSame('invalid_client', $response['error']);
    }

    public function testReconnectingReplacesThePreviousToken(): void
    {
        $this->exchange($this->authorizeAs($this->admin));
        $this->exchange($this->authorizeAs($this->admin));

        $this->assertSame(1, $this->em->getRepository(ApiToken::class)->count(['name' => OAuthController::TOKEN_NAME]));
    }

    public function testUnknownRedirectUriIsNotFollowed(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/oauth/authorize?'.http_build_query(['redirect_uri' => 'https://evil.example/cb'] + $this->authorizeQuery()));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testMemberCannotAuthorize(): void
    {
        $this->client->loginUser($this->member);
        $this->client->request('GET', '/oauth/authorize?'.http_build_query($this->authorizeQuery()));

        $this->assertResponseStatusCodeSame(400);
    }

    public function testDenyRedirectsWithAccessDenied(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/oauth/authorize?'.http_build_query($this->authorizeQuery()));
        $this->client->submitForm('Deny');

        $location = $this->client->getResponse()->headers->get('Location');
        $this->assertStringStartsWith(self::REDIRECT_URI.'?error=access_denied', $location);
        $this->assertStringContainsString('state=xyz', $location);
    }

    /**
     * Logs in, opens the consent page, allows, and returns the code.
     */
    private function authorizeAs(User $user): string
    {
        $this->client->loginUser($user);
        $this->client->request('GET', '/oauth/authorize?'.http_build_query($this->authorizeQuery()));
        $this->assertResponseIsSuccessful();

        $this->client->submitForm('Allow');

        $location = $this->client->getResponse()->headers->get('Location');
        $this->assertStringStartsWith(self::REDIRECT_URI.'?', $location);
        parse_str(parse_url($location, \PHP_URL_QUERY), $query);
        $this->assertSame('xyz', $query['state']);

        return $query['code'];
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, mixed>
     */
    private function exchange(string $code, array $overrides = []): array
    {
        $this->client->request('POST', '/oauth/token', $overrides + [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::REDIRECT_URI,
            'code_verifier' => self::VERIFIER,
            'client_id' => 'test-client',
            'client_secret' => 'test-secret',
        ]);

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    /**
     * @return array<string, string>
     */
    private function authorizeQuery(): array
    {
        return [
            'response_type' => 'code',
            'client_id' => 'test-client',
            'redirect_uri' => self::REDIRECT_URI,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', self::VERIFIER, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'state' => 'xyz',
            'resource' => 'http://localhost/mcp',
        ];
    }
}
