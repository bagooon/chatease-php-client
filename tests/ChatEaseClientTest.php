<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Bagooon\ChatEase\ChatEaseClient;

final class ChatEaseClientTest extends TestCase
{
    /**
     * HTTP 呼び出しをモックするためのテスト用クラス
     */
    private function createTestClient(array $fakeResponse, int $statusCode = 200): TestChatEaseClient
    {
        return new TestChatEaseClient(
            apiToken: 'test-token',
            workspaceSlug: 'test-workspace',
            baseUrl: 'https://example.com',
            fakeResponse: $fakeResponse,
            fakeStatusCode: $statusCode
        );
    }

    public function testCreateBoardSuccess(): void
    {
        $fakeResponse = [
            'slug'     => 'board-slug',
            'hostURL'  => 'https://host.example.com/board-slug',
            'guestURL' => 'https://guest.example.com/board-slug',
        ];

        $client = $this->createTestClient($fakeResponse);

        $result = $client->createBoard([
            'title' => 'お問い合わせ #1',
            'guest' => [
                'name'  => 'Taro',
                'email' => 'taro@example.com',
            ],
            'boardUniqueKey' => '20260225-0001',
        ]);

        $this->assertSame($fakeResponse['slug'], $result['slug']);
        $this->assertSame($fakeResponse['hostURL'], $result['hostURL']);
        $this->assertSame($fakeResponse['guestURL'], $result['guestURL']);

        // 正しい URL に POST されているか
        $this->assertSame('https://example.com/api/v1/board', $client->lastUrl);

        // body に workspaceSlug が入っているか
        $this->assertSame('test-workspace', $client->lastBody['workspaceSlug'] ?? null);
    }

    public function testCreateBoardWithStatusRequiresValidDate(): void
    {
        $client = $this->createTestClient([
            'slug'     => 'slug',
            'hostURL'  => 'host',
            'guestURL' => 'guest',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('initialStatus.timeLimit must be a valid date');

        $client->createBoardWithStatus([
            'title' => '見積依頼 #2',
            'guest' => [
                'name'  => 'Hanako',
                'email' => 'hanako@example.com',
            ],
            'boardUniqueKey' => '20260225-0002',
            'initialStatus' => [
                'statusKey' => 'scheduled_for_response',
                'timeLimit' => '2026-02-31', // 存在しない日付
            ],
        ]);
    }

    public function testCreateBoardRejectsInvalidEmail(): void
    {
        $client = $this->createTestClient([
            'slug'     => 'slug',
            'hostURL'  => 'host',
            'guestURL' => 'guest',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('guest.email is invalid');

        $client->createBoard([
            'title' => 'invalid mail',
            'guest' => [
                'name'  => 'Invalid',
                'email' => 'not-an-email',
            ],
            'boardUniqueKey' => '20260225-0003',
        ]);
    }

    public function testCreateBoardRejectsInvalidBoardUniqueKey(): void
    {
        $client = $this->createTestClient([
            'slug'     => 'slug',
            'hostURL'  => 'host',
            'guestURL' => 'guest',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('boardUniqueKey is invalid');

        $client->createBoard([
            'title' => 'invalid key',
            'guest' => [
                'name'  => 'Taro',
                'email' => 'taro@example.com',
            ],
            'boardUniqueKey' => 'has space', // 空白入り
        ]);
    }

    public function testApiErrorThrowsRuntimeException(): void
    {
        $client = $this->createTestClient(
            fakeResponse: ['error' => 'Bad request'],
            statusCode: 400,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ChatEase API error: 400');

        $client->createBoard([
            'title' => 'bad request',
            'guest' => [
                'name'  => 'Taro',
                'email' => 'taro@example.com',
            ],
            'boardUniqueKey' => '20260225-0004',
        ]);
    }
}

/**
 * HTTP を差し替えるためのテスト用サブクラス
 */
final class TestChatEaseClient extends ChatEaseClient
{
    /** @var array<string,mixed> */
    public array $lastBody = [];

    public string $lastUrl = '';

    /** @param array<string,mixed> $fakeResponse */
    public function __construct(
        string $apiToken,
        string $workspaceSlug,
        ?string $baseUrl,
        private array $fakeResponse,
        private int $fakeStatusCode = 200,
    ) {
        parent::__construct($apiToken, $workspaceSlug, $baseUrl);
    }

    /**
     * 本番では cURL を叩くところを、テストではフェイクレスポンスを返す
     *
     * @param array<string,mixed> $body
     */
    protected function postJson(string $url, array $body): mixed
    {
        $this->lastUrl  = $url;
        $this->lastBody = $body;

        if ($this->fakeStatusCode < 200 || $this->fakeStatusCode >= 300) {
            // 本体の postJson と似た形式で例外を投げる
            throw new RuntimeException(
                sprintf(
                    'ChatEase API error: %d - %s',
                    $this->fakeStatusCode,
                    json_encode($this->fakeResponse, JSON_UNESCAPED_UNICODE)
                )
            );
        }

        return $this->fakeResponse;
    }
}