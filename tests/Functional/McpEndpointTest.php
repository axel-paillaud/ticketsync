<?php

namespace App\Tests\Functional;

use App\Entity\Activity;
use App\Entity\ApiToken;
use App\Entity\Comment;
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

    public function testGetTicketReturnsThreadAndActivities(): void
    {
        $ticket = $this->createTicket($this->member);
        // Persisted out of order on purpose: the tool must sort them by date.
        $this->em->persist((new Comment())->setTicket($ticket)->setAuthor($this->admin)->setContent('Fixed.'));
        $this->em->persist((new Comment())->setTicket($ticket)->setAuthor($this->member)->setContent('It is broken.'));
        foreach ([['2026-03-02', '1.25'], ['2026-03-01', '0.50']] as [$date, $hours]) {
            $this->em->persist((new Activity())
                ->setTicket($ticket)->setOrganization($this->organization)->setCreatedBy($this->admin)
                ->setWorkDate(new \DateTimeImmutable($date))->setHours($hours)->setDescription('Work on '.$date));
        }
        $this->em->flush();
        // createdAt is forced to "now" on persist, so set the real dates directly.
        $connection = $this->em->getConnection();
        $connection->executeStatement("UPDATE comment SET created_at = '2026-03-02 10:00:00' WHERE content = 'Fixed.'");
        $connection->executeStatement("UPDATE comment SET created_at = '2026-03-01 09:00:00' WHERE content = 'It is broken.'");
        $this->em->clear();

        $result = $this->callTool('get_ticket', ['id' => $ticket->getId()]);

        $this->assertFalse($result['isError'] ?? false);
        $data = $result['structuredContent'];
        $this->assertSame('Broken printer', $data['title']);
        $this->assertSame('It does not print.', $data['description']);
        $this->assertSame(['It is broken.', 'Fixed.'], array_column($data['comments'], 'content'));
        $this->assertSame([false, true], array_column($data['comments'], 'authorIsAdmin'));
        $this->assertSame(['2026-03-01', '2026-03-02'], array_column($data['activities'], 'workDate'));
        $this->assertEqualsWithDelta(1.75, $data['totalHours'], 0.001);
    }

    public function testGetUnknownTicketReturnsAToolError(): void
    {
        $result = $this->callTool('get_ticket', ['id' => 999]);

        $this->assertTrue($result['isError']);
        $this->assertSame('Ticket #999 not found.', $result['content'][0]['text']);
    }

    /**
     * Opens an MCP session as admin and calls one tool.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function callTool(string $name, array $arguments): array
    {
        $token = $this->createToken($this->admin);
        $this->postJsonRpc($token, 'initialize', $this->initializeParams());
        $sessionId = $this->client->getResponse()->headers->get('Mcp-Session-Id');

        $response = $this->postJsonRpc($token, 'tools/call', ['name' => $name, 'arguments' => $arguments], $sessionId);
        $this->assertResponseIsSuccessful();

        return $response['result'];
    }

    private function createToken(User $user): string
    {
        // getReference() also works when the entity manager was cleared since $user was loaded.
        [$token, $plainToken] = ApiToken::generate($this->em->getReference(User::class, $user->getId()), 'test');
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
