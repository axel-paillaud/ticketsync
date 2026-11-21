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
    /** Both clients are configured in .env.test. */
    private const TMA_CLIENT = ['id' => 'test-client', 'secret' => 'test-secret', 'resource' => 'http://localhost/mcp'];
    private const NOTES_CLIENT = ['id' => 'test-notes-client', 'secret' => 'test-notes-secret', 'resource' => 'http://localhost/mcp/notes'];

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

    public function testNotesClientGetsATokenForTheNotesServerOnly(): void
    {
        $this->client->request('GET', '/.well-known/oauth-protected-resource/mcp/notes');
        $this->assertSame('http://localhost/mcp/notes', json_decode($this->client->getResponse()->getContent(), true)['resource']);

        $code = $this->authorizeAs($this->admin, self::NOTES_CLIENT);
        $token = $this->exchange($code, self::NOTES_CLIENT)['access_token'];

        $this->assertSame(ApiToken::SCOPE_NOTES, $this->em->getRepository(ApiToken::class)->findOneBy(['name' => OAuthController::NOTES_TOKEN_NAME])->getScope());
        $this->initializeMcp('/mcp/notes', $token);
        $this->assertResponseIsSuccessful();
        $this->initializeMcp('/mcp', $token);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testConsentPageTellsWhatTheNotesClientCanDo(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/oauth/authorize?'.http_build_query($this->authorizeQuery(self::NOTES_CLIENT)));

        $this->assertSelectorTextContains('main, body', 'org-mode');
        $this->assertSelectorTextNotContains('main, body', 'tickets');
    }

    public function testBothConnectorsKeepTheirOwnToken(): void
    {
        $this->exchange($this->authorizeAs($this->admin));
        $this->exchange($this->authorizeAs($this->admin, self::NOTES_CLIENT), self::NOTES_CLIENT);

        $this->assertSame(2, $this->em->getRepository(ApiToken::class)->count([]));
    }

    public function testClientCannotAskForTheOtherResource(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/oauth/authorize?'.http_build_query(['resource' => 'http://localhost/mcp'] + $this->authorizeQuery(self::NOTES_CLIENT)));

        $this->assertStringStartsWith(self::REDIRECT_URI.'?error=invalid_target', $this->client->getResponse()->headers->get('Location'));
    }

    public function testCodeIsBoundToItsClient(): void
    {
        $code = $this->authorizeAs($this->admin, self::NOTES_CLIENT);

        $response = $this->exchange($code);

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('invalid_grant', $response['error']);
    }

    private function initializeMcp(string $path, string $token): void
    {
        $this->client->request('POST', $path, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'phpunit', 'version' => '1']],
        ]));
    }

    /**
     * Logs in, opens the consent page, allows, and returns the code.
     *
     * @param array{id: string, secret: string, resource: string} $oauthClient
     */
    private function authorizeAs(User $user, array $oauthClient = self::TMA_CLIENT): string
    {
        $this->client->loginUser($user);
        $this->client->request('GET', '/oauth/authorize?'.http_build_query($this->authorizeQuery($oauthClient)));
        $this->assertResponseIsSuccessful();

        $this->client->submitForm('Allow');

        $location = $this->client->getResponse()->headers->get('Location');
        $this->assertStringStartsWith(self::REDIRECT_URI.'?', $location);
        parse_str(parse_url($location, \PHP_URL_QUERY), $query);
        $this->assertSame('xyz', $query['state']);

        return $query['code'];
    }

    /**
     * @param array{id: string, secret: string, resource: string}|array<string, string> $clientOrOverrides
     *
     * @return array<string, mixed>
     */
    private function exchange(string $code, array $clientOrOverrides = []): array
    {
        $oauthClient = isset($clientOrOverrides['resource']) ? $clientOrOverrides : self::TMA_CLIENT;
        $overrides = isset($clientOrOverrides['resource']) ? [] : $clientOrOverrides;

        $this->client->request('POST', '/oauth/token', $overrides + [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::REDIRECT_URI,
            'code_verifier' => self::VERIFIER,
            'client_id' => $oauthClient['id'],
            'client_secret' => $oauthClient['secret'],
        ]);

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    /**
     * @param array{id: string, secret: string, resource: string} $oauthClient
     *
     * @return array<string, string>
     */
    private function authorizeQuery(array $oauthClient = self::TMA_CLIENT): array
    {
        return [
            'response_type' => 'code',
            'client_id' => $oauthClient['id'],
            'redirect_uri' => self::REDIRECT_URI,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', self::VERIFIER, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'state' => 'xyz',
            'resource' => $oauthClient['resource'],
        ];
    }
}
