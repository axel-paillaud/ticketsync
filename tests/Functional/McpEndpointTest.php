<?php

namespace App\Tests\Functional;

use App\Entity\Activity;
use App\Entity\ApiToken;
use App\Entity\Comment;
use App\Entity\Organization;
use App\Entity\Priority;
use App\Entity\Ticket;
use App\Entity\User;
use Symfony\Component\Mime\Email;

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

    public function testLogActivityCreatesThenUpdatesTheSameSession(): void
    {
        $ticket = $this->createTicket($this->member);
        $arguments = [
            'ticketId' => $ticket->getId(),
            'orgId' => '3f2a9c4e-0000-4000-8000-000000000001',
            'workDate' => '2026-11-18',
            'minutes' => 27,
            'description' => 'Fix VAT rounding in `HTMLTemplateInvoice::getHeader()`.',
        ];

        $first = $this->callTool('log_activity', $arguments)['structuredContent'];
        $this->assertSame('created', $first['result']);
        // JSON numbers: compare values, 1.0 may come back as 1.
        $this->assertEquals(0.5, $first['hours']);
        $this->assertEquals(0.5, $first['ticketTotalHours']);

        // More time clocked on the same org session, then synced again.
        $second = $this->callTool('log_activity', ['minutes' => 50, 'description' => 'Fix VAT rounding, credit notes included.'] + $arguments)['structuredContent'];
        $this->assertSame('updated', $second['result']);
        $this->assertSame($first['activityId'], $second['activityId']);
        $this->assertEquals(1.0, $second['hours']);

        $this->em->clear();
        $activities = $this->em->getRepository(Activity::class)->findAll();
        $this->assertCount(1, $activities, 'Re-syncing a session must not create a duplicate.');
        // SQLite NUMERIC stores 1.00 as 1: compare the value (display always uses number_format).
        $this->assertEquals(1.0, (float) $activities[0]->getHours());
        $this->assertSame('Fix VAT rounding, credit notes included.', $activities[0]->getDescription());
        $this->assertSame('admin@example.com', $activities[0]->getCreatedBy()->getEmail());
        $this->assertSame($this->organization->getId(), $activities[0]->getOrganization()->getId());
    }

    public function testLogActivityRefusesAnOrgIdAlreadyUsedOnAnotherTicket(): void
    {
        $first = $this->createTicket($this->member);
        $second = $this->createTicket($this->member);
        $arguments = ['orgId' => 'same-id', 'workDate' => '2026-11-18', 'minutes' => 10, 'description' => 'Work.'];

        $this->callTool('log_activity', ['ticketId' => $first->getId()] + $arguments);
        $result = $this->callTool('log_activity', ['ticketId' => $second->getId()] + $arguments);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('already used by an activity of ticket #'.$first->getId(), $result['content'][0]['text']);
    }

    public function testLogActivityRejectsInvalidDates(): void
    {
        $ticket = $this->createTicket($this->member);
        $arguments = ['ticketId' => $ticket->getId(), 'orgId' => 'some-id', 'minutes' => 10, 'description' => 'Work.'];

        $future = $this->callTool('log_activity', ['workDate' => (new \DateTimeImmutable('+2 days'))->format('Y-m-d')] + $arguments);
        $this->assertTrue($future['isError']);
        $this->assertStringContainsString('in the future', $future['content'][0]['text']);

        // Rejected by the JSON schema ("format: date") before the tool even runs.
        $invalid = $this->callTool('log_activity', ['workDate' => '2026-02-30'] + $arguments);
        $this->assertArrayHasKey('protocolError', $invalid);

        $this->assertSame(0, $this->em->getRepository(Activity::class)->count([]));
    }

    public function testLogActivityLeavesManualActivitiesAlone(): void
    {
        $ticket = $this->createTicket($this->member);
        $this->em->persist((new Activity())
            ->setTicket($ticket)->setOrganization($this->organization)->setCreatedBy($this->admin)
            ->setWorkDate(new \DateTimeImmutable('2026-11-17'))->setHours('0.25')->setDescription('Entered by hand.'));
        $this->em->flush();

        $result = $this->callTool('log_activity', [
            'ticketId' => $ticket->getId(), 'orgId' => 'new-session', 'workDate' => '2026-11-18', 'minutes' => 20, 'description' => 'Synced.',
        ])['structuredContent'];

        $this->assertSame('created', $result['result']);
        $this->assertEquals(0.75, $result['ticketTotalHours']);
        $this->em->clear();
        $manual = $this->em->getRepository(Activity::class)->findOneBy(['description' => 'Entered by hand.']);
        $this->assertNull($manual->getOrgId());
        $this->assertEquals(0.25, (float) $manual->getHours());
    }

    public function testCreateTicketInTheAdminName(): void
    {
        $this->createStatus('open', false);
        $this->createPriorities();
        $this->em->flush();

        $result = $this->callTool('create_ticket', [
            'organization' => $this->organization->getSlug(),
            'title' => '  Le paiement échoue  ',
            'description' => 'Erreur 500 au retour de la banque.',
        ]);

        $this->assertFalse($result['isError'] ?? false);
        $data = $result['structuredContent'];
        $this->assertSame('Le paiement échoue', $data['title']);
        $this->assertSame('open', $data['status']);
        $this->assertSame('Priority B', $data['priority'], 'Medium priority by default.');
        $this->assertStringEndsWith('/tickets/'.$data['id'], $data['url']);

        $this->em->clear();
        $ticket = $this->em->getRepository(Ticket::class)->find($data['id']);
        $this->assertSame('admin@example.com', $ticket->getCreatedBy()->getEmail());
        $this->assertSame($this->organization->getId(), $ticket->getOrganization()->getId());

        // Same notifications as from the website: everyone but the author.
        $recipients = array_map(
            static fn (Email $email) => $email->getTo()[0]->getAddress(),
            array_filter($this->getMailerMessages(), static fn ($m) => $m instanceof Email),
        );
        $this->assertSame(['member@example.com'], array_values($recipients));
    }

    public function testCreateTicketOnBehalfOfAMember(): void
    {
        $this->createStatus('open', false);
        $this->createPriorities();
        $this->em->flush();

        $data = $this->callTool('create_ticket', [
            'organization' => $this->organization->getSlug(),
            'title' => 'Urgent',
            'priority' => 'A',
            'requestedBy' => 'MEMBER@example.com',
        ])['structuredContent'];

        $this->assertSame('Priority A', $data['priority']);
        $this->em->clear();
        $ticket = $this->em->getRepository(Ticket::class)->find($data['id']);
        $this->assertSame('member@example.com', $ticket->getCreatedBy()->getEmail());
        $this->assertNull($ticket->getDescription());
    }

    public function testCreateTicketRefusesARequesterOutsideTheOrganization(): void
    {
        $this->createStatus('open', false);
        $this->createPriorities();
        $other = (new Organization())->setName('Other')->setIsActive(true);
        $this->em->persist($other);
        $this->em->persist((new User())->setEmail('outsider@example.com')->setRoles([])->setPassword('x')->setIsVerified(true)->setOrganization($other));
        $this->em->flush();

        $result = $this->callTool('create_ticket', [
            'organization' => $this->organization->getSlug(),
            'title' => 'Hello',
            'requestedBy' => 'outsider@example.com',
        ]);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('No member of "'.$this->organization->getSlug().'"', $result['content'][0]['text']);
        $this->assertSame(0, $this->em->getRepository(Ticket::class)->count([]));
    }

    public function testCreateTicketRejectsAnUnknownOrganization(): void
    {
        $result = $this->callTool('create_ticket', ['organization' => 'nope', 'title' => 'Hello']);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('Unknown organization "nope"', $result['content'][0]['text']);
    }

    private function createPriorities(): void
    {
        foreach (['A' => 3, 'B' => 2, 'C' => 1] as $name => $level) {
            $this->em->persist((new Priority())->setName('Priority '.$name)->setLevel($level));
        }
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

        // Arguments rejected by the tool's input schema come back as a JSON-RPC error, not a result.
        return $response['result'] ?? ['protocolError' => $response['error']];
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
