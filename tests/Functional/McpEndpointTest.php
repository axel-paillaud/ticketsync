<?php

namespace App\Tests\Functional;

use App\Entity\ApiToken;
use App\Entity\User;

/**
 * The /mcp endpoint: bearer token authentication, admin-only access, and a
 * full JSON-RPC round trip (initialize, then a tools/call).
 */
class McpEndpointTest extends FunctionalTestCase
{
    public function testRequestWithoutTokenIsRejected(): void
    {
        $this->client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testRequestWithUnknownTokenIsRejected(): void
    {
        $this->postJsonRpc('tks_unknown', 'initialize', $this->initializeParams());

        $this->assertResponseStatusCodeSame(401);
    }

    public function testNonAdminTokenIsForbidden(): void
    {
        $token = $this->createToken($this->member);

        $this->postJsonRpc($token, 'initialize', $this->initializeParams());

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminCanListTicketsOfAnOrganization(): void
    {
        $ticket = $this->createTicket($this->member);
        $token = $this->createToken($this->admin);

        $this->postJsonRpc($token, 'initialize', $this->initializeParams());
        $this->assertResponseIsSuccessful();
        $sessionId = $this->client->getResponse()->headers->get('Mcp-Session-Id');
        $this->assertNotEmpty($sessionId);

        $response = $this->postJsonRpc($token, 'tools/call', [
            'name' => 'list_tickets',
            'arguments' => ['organization' => $this->organization->getSlug()],
        ], $sessionId);

        $this->assertResponseIsSuccessful();
        $result = $response['result'];
        $this->assertFalse($result['isError'] ?? false);
        $this->assertSame(1, $result['structuredContent']['count']);
        $this->assertSame($ticket->getId(), $result['structuredContent']['tickets'][0]['id']);

        $this->em->clear();
        $stored = $this->em->getRepository(ApiToken::class)->findOneBy([]);
        $this->assertNotNull($stored->getLastUsedAt());
    }

    private function createToken(User $user): string
    {
        [$token, $plainToken] = ApiToken::generate($user, 'test');
        $this->em->persist($token);
        $this->em->flush();

        return $plainToken;
    }

    /**
     * @return array<string, mixed>
     */
    private function initializeParams(): array
    {
        return [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'phpunit', 'version' => '1'],
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>|null
     */
    private function postJsonRpc(string $token, string $method, array $params, ?string $sessionId = null): ?array
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ];
        if ($sessionId !== null) {
            $server['HTTP_MCP_SESSION_ID'] = $sessionId;
            $server['HTTP_MCP_PROTOCOL_VERSION'] = '2025-11-25';
        }

        $this->client->request('POST', '/mcp', server: $server, content: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ]));

        return json_decode($this->client->getResponse()->getContent(), true);
    }
}
