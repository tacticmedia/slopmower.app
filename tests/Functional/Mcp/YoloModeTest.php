<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mcp;

use App\Domain\Issue\ApprovalMode;
use App\Tests\Support\Api\ApiFixtures;
use App\Tests\Support\Contract\JsonApiAssertions;
use App\Tests\Support\Database\ResetDatabase;
use App\Tests\Support\Mcp\McpAssertions;
use App\Tests\Support\Mcp\McpTestClient;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "YOLO mode" of docs/specs/mcp.md (D148). The agent created SM-1 with the approval user in the by_issue project SM, and
 * the session HOLDER holds it. The signed-in user turns YOLO mode on with P3.
 */
final class YoloModeTest extends WebTestCase
{
    use ApiFixtures;
    use JsonApiAssertions;
    use McpAssertions;
    use ResetDatabase;

    private const string HOLDER = 'billing-3f9a';
    private const string OTHER = 'billing-77c0';
    private const string YOLO_MODE_OFF = 'YOLO mode must be enabled by the user. Please ask the user to go to Project Settings and tick the YOLO mode checkbox';

    private KernelBrowser $client;

    private McpTestClient $mcp;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $user = self::signIn($this->client);
        $this->mcp = new McpTestClient($this->client, self::storeToken($user)[1]);
        self::storeProject($user->getTenantId(), 'SM', ApprovalMode::ByIssue);
        self::storeProject($user->getTenantId(), 'OPS');
        $this->call('create_issue', ['project' => 'SM', 'title' => 'Round invoice totals once', 'category' => 'change', 'problem' => 'Invoice totals round each line.', 'status' => 'todo', 'approval' => 'user']);
        $this->call('update_issue', ['issue' => 'SM-1', 'session' => self::HOLDER, 'notes' => 'Rounding is in `Invoice::total()`.']);
    }

    #[TestDox('With YOLO mode off, each YOLO tool fails with forbidden and the message, also before a claim of another session, and changes nothing')]
    public function testYoloModeOff(): void
    {
        $errors = [
            self::assertToolError($this->mcp->callTool('yolo_set_approval', ['issue' => 'SM-1', 'session' => self::OTHER, 'approval' => 'self']), 'forbidden'),
            self::assertToolError($this->mcp->callTool('yolo_release_claim', ['issue' => 'SM-1']), 'forbidden'),
        ];
        $issue = $this->call('get_issue', ['issue' => 'SM-1'])['issue'];

        self::assertSame([self::YOLO_MODE_OFF, self::YOLO_MODE_OFF], array_column($errors, 'message'));
        self::assertSame(['user', self::HOLDER], [$issue['approval'], $issue['claim']['session']]);
    }

    #[TestDox('In YOLO mode, yolo_set_approval lowers the approval that update_issue only raises, and takes the claim')]
    public function testSetApproval(): void
    {
        $this->turnOnYoloMode();

        $refused = self::assertToolError($this->mcp->callTool('update_issue', ['issue' => 'SM-1', 'session' => self::HOLDER, 'approval' => 'self']), 'invalid_input');
        $lowered = $this->call('yolo_set_approval', ['issue' => 'SM-1', 'session' => self::HOLDER, 'approval' => 'self']);
        $byOther = self::assertToolError($this->mcp->callTool('yolo_set_approval', ['issue' => 'SM-1', 'session' => self::OTHER, 'approval' => 'agent_review']), 'invalid_input');

        self::assertSame(['approval'], array_column($refused['details']['violations'] ?? [], 'field'));
        self::assertSame(['self', self::HOLDER], [$lowered['approval'], $lowered['claim']['session']]);
        self::assertSame(['session'], array_column($byOther['details']['violations'] ?? [], 'field'));
    }

    #[TestDox('In YOLO mode, yolo_release_claim removes the claim of another session, which the next write takes')]
    public function testReleaseClaim(): void
    {
        $this->turnOnYoloMode();

        $released = $this->call('yolo_release_claim', ['issue' => 'SM-1']);
        $taken = $this->call('update_issue', ['issue' => 'SM-1', 'session' => self::OTHER, 'notes' => 'The test is next.']);

        self::assertNull($released['claim']);
        self::assertSame(self::OTHER, $taken['claim']['session']);
    }

    #[TestDox('resources/list names yolo_mode, and resources/read returns each project of the token with its YOLO mode')]
    public function testResource(): void
    {
        $this->turnOnYoloMode();

        $listed = $this->mcp->request('resources/list')['result']['resources'] ?? null;
        $read = $this->mcp->request('resources/read', ['uri' => 'slopmower://yolo_mode'])['result']['contents'] ?? null;

        self::assertIsArray($listed);
        self::assertIsArray($read);
        self::assertSame([['slopmower://yolo_mode', 'yolo_mode', 'application/json']], array_map(static fn (array $resource): array => [$resource['uri'], $resource['name'], $resource['mimeType']], $listed));
        self::assertSame('application/json', $read[0]['mimeType']);
        self::assertSame(['projects' => [['key' => 'OPS', 'yoloMode' => false], ['key' => 'SM', 'yoloMode' => true]]], json_decode($read[0]['text'], true, flags: \JSON_THROW_ON_ERROR));
    }

    private function turnOnYoloMode(): void
    {
        self::assertApiResponse(self::apiRequest($this->client, 'PATCH', '/api/v1/projects/SM', ['yoloMode' => true]), 200, 'project.schema.json');
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function call(string $tool, array $arguments): array
    {
        return self::assertToolResult($this->mcp, $this->mcp->callTool($tool, $arguments), $tool);
    }
}
