<?php

namespace App\Tests\Functional;

use App\Entity\ApiToken;
use App\Entity\User;
use Symfony\Component\Process\Process;

/**
 * The notes MCP server (/mcp/notes): kept apart from /mcp by token scope,
 * and capture_note writing to a real git clone pushed to a local remote.
 *
 * Three repositories under /tmp: the remote (bare, stands for GitHub), the
 * server clone (ORG_NOTES_DIR in .env.test) and the user's clone (Emacs).
 */
class NotesMcpTest extends FunctionalTestCase
{
    private const SERVER = '/tmp/ticketsync-test-org-notes';
    private const REMOTE = '/tmp/ticketsync-test-org-notes-remote.git';
    private const USER = '/tmp/ticketsync-test-org-notes-user';

    protected function setUp(): void
    {
        parent::setUp();

        self::sh('rm -rf '.self::SERVER.' '.self::REMOTE.' '.self::USER);
        self::sh('git init --quiet --bare -b main '.self::REMOTE);
        self::sh('git clone --quiet '.self::REMOTE.' '.self::USER);
        file_put_contents(self::USER.'/refile.org', "* Refile\n");
        $this->userGit('add refile.org');
        $this->userGit('commit --quiet -m init');
        $this->userGit('push --quiet origin HEAD:main');
        self::sh('git clone --quiet '.self::REMOTE.' '.self::SERVER);
    }

    public function testTokensOnlyOpenTheServerOfTheirScope(): void
    {
        $tma = $this->createToken($this->admin, ApiToken::SCOPE_TMA);
        $notes = $this->createToken($this->admin, ApiToken::SCOPE_NOTES);

        $this->postJsonRpc('/mcp/notes', $tma, 'initialize', $this->initializeParams());
        $this->assertResponseStatusCodeSame(401);
        $this->assertStringContainsString(
            'resource_metadata="http://localhost/.well-known/oauth-protected-resource/mcp/notes"',
            $this->client->getResponse()->headers->get('WWW-Authenticate'),
        );

        $this->postJsonRpc('/mcp', $notes, 'initialize', $this->initializeParams());
        $this->assertResponseStatusCodeSame(401);

        $this->postJsonRpc('/mcp/notes', $notes, 'initialize', $this->initializeParams());
        $this->assertResponseIsSuccessful();
    }

    public function testNonAdminNotesTokenIsForbidden(): void
    {
        $this->postJsonRpc('/mcp/notes', $this->createToken($this->member, ApiToken::SCOPE_NOTES), 'initialize', $this->initializeParams());

        $this->assertResponseStatusCodeSame(403);
    }

    public function testEachServerOnlyListsItsOwnTools(): void
    {
        $this->assertSame(['capture_note'], $this->listTools('/mcp/notes', ApiToken::SCOPE_NOTES));

        $tmaTools = $this->listTools('/mcp', ApiToken::SCOPE_TMA);
        $this->assertContains('log_activity', $tmaTools);
        $this->assertNotContains('capture_note', $tmaTools);
    }

    public function testCaptureWritesCommitsAndPushesOneInboxFile(): void
    {
        $result = $this->callCapture([
            'title' => 'Primaire au zinc sous la banquette',
            'body' => 'Pièce extérieure préparée, primaire au zinc appliqué.',
            'tags' => ['mercedes'],
            'clocks' => [['start' => '2026-11-20T08:00', 'end' => '2026-11-20T12:00']],
        ]);

        $this->assertFalse($result['isError'] ?? false, json_encode($result));
        $data = $result['structuredContent'];
        $this->assertSame('created', $data['result']);
        $this->assertTrue($data['pushed']);
        $this->assertSame(240, $data['clockedMinutes']);
        $this->assertMatchesRegularExpression('#^inbox/\d{8}-\d{6}-primaire-au-zinc-sous-la-banquette-[0-9a-f]{6}\.org$#', $data['file']);

        // What the user gets on their side after a pull.
        $this->userGit('pull --quiet');
        $content = file_get_contents(self::USER.'/'.$data['file']);
        $this->assertSame($data['org'], $content);
        $this->assertStringContainsString(':ID:       '.$data['id'], $content);
        $this->assertStringContainsString('CLOCK: [2026-11-20 ven. 08:00]--[2026-11-20 ven. 12:00] =>  4:00', $content);
        $this->assertSame("* Refile\n", file_get_contents(self::USER.'/refile.org'));
        $this->assertSame('ticketsync-bot', trim($this->userGit('log -1 --format=%an')));
        $this->assertSame('inbox: Primaire au zinc sous la banquette', trim($this->userGit('log -1 --format=%s')));
    }

    public function testCaptureRebasesOnWhatTheUserPushedMeanwhile(): void
    {
        // The user edits refile.org and pushes; the server clone is now behind.
        file_put_contents(self::USER.'/refile.org', "* Refile\n\nUne note écrite dans Emacs.\n");
        $this->userGit('commit --quiet -am edit');
        $this->userGit('push --quiet');

        $data = $this->callCapture(['title' => 'Idée', 'body' => 'Une idée.'])['structuredContent'];

        $this->assertTrue($data['pushed']);
        $this->assertSame([], $data['warnings']);
        $this->userGit('pull --quiet');
        $this->assertFileExists(self::USER.'/'.$data['file']);
        $this->assertStringContainsString('Une note écrite dans Emacs.', file_get_contents(self::SERVER.'/refile.org'));
    }

    public function testReplaceIdRewritesTheSameFile(): void
    {
        $first = $this->callCapture([
            'title' => 'Ponçage',
            'clocks' => [['start' => '2026-11-20T08:00', 'end' => '2026-11-20T12:00']],
        ])['structuredContent'];

        $second = $this->callCapture([
            'title' => 'Ponçage',
            'clocks' => [['start' => '2026-11-20T08:30', 'end' => '2026-11-20T12:00']],
            'replaceId' => $first['id'],
        ])['structuredContent'];

        $this->assertSame('updated', $second['result']);
        $this->assertSame($first['file'], $second['file']);
        $this->assertSame($first['id'], $second['id']);
        $this->userGit('pull --quiet');
        $this->assertSame([basename($first['file'])], array_map('basename', glob(self::USER.'/inbox/*.org')));
        $this->assertStringContainsString('08:30]', file_get_contents(self::USER.'/'.$first['file']));
    }

    public function testReplacingARefiledNoteIsAnError(): void
    {
        $first = $this->callCapture(['title' => 'Idée'])['structuredContent'];
        // The user merged the inbox into refile.org and pushed.
        $this->userGit('pull --quiet');
        $this->userGit('rm --quiet -- '.$first['file']);
        $this->userGit('commit --quiet -m refile');
        $this->userGit('push --quiet');

        $result = $this->callCapture(['title' => 'Idée corrigée', 'replaceId' => $first['id']]);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('already refiled', $result['content'][0]['text']);
        $this->assertSame([], glob(self::SERVER.'/inbox/*.org'));
    }

    public function testUnreachableRemoteKeepsTheNoteCommittedLocally(): void
    {
        self::sh('mv '.self::REMOTE.' '.self::REMOTE.'.away');

        $data = $this->callCapture(['title' => 'Hors ligne'])['structuredContent'];

        $this->assertFalse($data['pushed']);
        $this->assertCount(2, $data['warnings']);
        $this->assertFileExists(self::SERVER.'/'.$data['file']);

        // Back online: the next capture pushes both.
        self::sh('mv '.self::REMOTE.'.away '.self::REMOTE);
        $next = $this->callCapture(['title' => 'En ligne'])['structuredContent'];
        $this->assertTrue($next['pushed']);
        $this->userGit('pull --quiet');
        $this->assertCount(2, glob(self::USER.'/inbox/*.org'));
    }

    public function testInvalidClockIsAToolError(): void
    {
        $result = $this->callCapture(['title' => 'Note', 'clocks' => [['start' => '2026-11-20T12:00', 'end' => '2026-11-20T08:00']]]);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('end must be after start', $result['content'][0]['text']);
        $this->assertSame([], glob(self::SERVER.'/inbox/*.org'));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function callCapture(array $arguments): array
    {
        $token = $this->createToken($this->admin, ApiToken::SCOPE_NOTES);
        $this->postJsonRpc('/mcp/notes', $token, 'initialize', $this->initializeParams());
        $sessionId = $this->client->getResponse()->headers->get('Mcp-Session-Id');

        $response = $this->postJsonRpc('/mcp/notes', $token, 'tools/call', ['name' => 'capture_note', 'arguments' => $arguments], $sessionId);
        $this->assertResponseIsSuccessful();

        return $response['result'] ?? ['protocolError' => $response['error']];
    }

    /**
     * @return list<string>
     */
    private function listTools(string $path, string $scope): array
    {
        $token = $this->createToken($this->admin, $scope);
        $this->postJsonRpc($path, $token, 'initialize', $this->initializeParams());
        $sessionId = $this->client->getResponse()->headers->get('Mcp-Session-Id');

        $response = $this->postJsonRpc($path, $token, 'tools/list', [], $sessionId);

        return array_column($response['result']['tools'], 'name');
    }

    private function createToken(User $user, string $scope): string
    {
        [$token, $plainToken] = ApiToken::generate($this->em->getReference(User::class, $user->getId()), 'test', $scope);
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
    private function postJsonRpc(string $path, string $token, string $method, array $params, ?string $sessionId = null): ?array
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

        $this->client->request('POST', $path, server: $server, content: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params === [] ? new \stdClass() : $params,
        ]));

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    private function userGit(string $arguments): string
    {
        return self::sh('git -C '.self::USER.' -c user.name=user -c user.email=user@example.com '.$arguments);
    }

    private static function sh(string $command): string
    {
        $process = Process::fromShellCommandline($command);
        $process->mustRun();

        return $process->getOutput();
    }
}
