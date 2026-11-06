<?php

use PHPUnit\Framework\TestCase;

define('NOTION_TODO_SECRETS_DIR', sys_get_temp_dir() . '/brocs-notion-secrets-' . getmypid());
define('NOTION_TODO_DATA_DIR', sys_get_temp_dir() . '/brocs-notion-data-' . getmypid());

require_once __DIR__ . '/../includes/notion-todo.php';

/** Transport simulé : enregistre les requêtes et renvoie les réponses préparées. */
final class FakeNotionTransport
{
    public array $requests = [];
    private array $queue;

    public function __construct(array $queue)
    {
        $this->queue = $queue;
    }

    public function __invoke(string $method, string $url, ?array $body, array $headers, int $timeout): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $body, 'headers' => $headers];
        return array_shift($this->queue) ?? ['status' => 500, 'json' => ['code' => 'unexpected', 'message' => 'réponse non prévue'], 'error' => ''];
    }
}

final class NotionTodoTest extends TestCase
{
    private const DATABASE = '19cde2b8-eea4-49d7-a883-a24a8cef20db';

    protected function setUp(): void
    {
        $this->clear();
        $this->configure();
    }

    protected function tearDown(): void
    {
        $this->clear();
    }

    private function clear(): void
    {
        foreach ([NOTION_TODO_SECRETS_DIR, NOTION_TODO_DATA_DIR] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    private function configure(): void
    {
        if (!is_dir(NOTION_TODO_SECRETS_DIR)) {
            mkdir(NOTION_TODO_SECRETS_DIR, 0700, true);
        }
        file_put_contents(NOTION_TODO_SECRETS_DIR . '/notion.json', json_encode([
            'token' => 'ntn_' . str_repeat('a', 40),
            'database_id' => self::DATABASE,
        ]));
    }

    private function record(array $overrides = []): array
    {
        return array_merge([
            'id' => 'fb261103-abcdef12',
            'at' => '2026-11-03T12:58:00+00:00',
            'shop' => 'la-fripe-a-naty',
            'shop_name' => 'La fripe à Naty',
            'source' => 'equipe',
            'type' => 'bug',
            'severity' => 'major',
            'priority' => 'normal',
            'message' => 'Les tarifs des packs ne se mettent pas à jour sur la page des offres.',
            'page' => '/portail/offres.php',
            'url' => 'https://brocs.arrimage.com/portail/offres.php?boutique=demo',
        ], $overrides);
    }

    private function emptyQuery(): array
    {
        return ['status' => 200, 'json' => ['results' => []], 'error' => ''];
    }

    private function createdPage(string $id = 'page-1'): array
    {
        return ['status' => 200, 'json' => ['object' => 'page', 'id' => $id], 'error' => ''];
    }

    public function testNotConfiguredDoesNothing(): void
    {
        unlink(NOTION_TODO_SECRETS_DIR . '/notion.json');
        $transport = new FakeNotionTransport([]);
        self::assertNull(notion_todo_import($this->record(), $transport));
        self::assertSame([], $transport->requests);
    }

    public function testCreatesPageWithMappedProperties(): void
    {
        $transport = new FakeNotionTransport([$this->emptyQuery(), $this->createdPage('page-42')]);

        self::assertSame('page-42', notion_todo_import($this->record(), $transport));
        self::assertCount(2, $transport->requests);

        $query = $transport->requests[0];
        self::assertSame('POST', $query['method']);
        self::assertStringEndsWith('/v1/databases/' . self::DATABASE . '/query', $query['url']);
        self::assertSame('fb261103-abcdef12', $query['body']['filter']['rich_text']['contains']);

        $create = $transport->requests[1];
        self::assertStringEndsWith('/v1/pages', $create['url']);
        self::assertSame(self::DATABASE, $create['body']['parent']['database_id']);
        $props = $create['body']['properties'];
        self::assertStringContainsString('fb261103-abcdef12', $props['Jalon']['title'][0]['text']['content']);
        self::assertStringContainsString('https://brocs.arrimage.com/portail/offres.php?boutique=demo', $props['Critère de done']['rich_text'][0]['text']['content']);
        self::assertSame('Pricing', $props['Domaine']['select']['name']);
        self::assertSame('P1', $props['Priorité']['select']['name']);
        self::assertSame('À faire', $props['Statut']['status']['name']);
        self::assertStringContainsString('Bearer ntn_', $create['headers'][0]);

        $state = notion_todo_state();
        self::assertSame('fb261103-abcdef12', $state['last_ref']);
        self::assertSame('', $state['last_error']);
    }

    public function testExistingPageIsReused(): void
    {
        $transport = new FakeNotionTransport([
            ['status' => 200, 'json' => ['results' => [['id' => 'page-existing']]], 'error' => ''],
        ]);

        self::assertSame('page-existing', notion_todo_import($this->record(), $transport));
        self::assertCount(1, $transport->requests);
    }

    public function testShortMessageIsIgnored(): void
    {
        $transport = new FakeNotionTransport([]);
        self::assertNull(notion_todo_import($this->record(['message' => 'dsddds dssd']), $transport));
        self::assertSame([], $transport->requests);
    }

    public function testUnknownReferenceIsIgnored(): void
    {
        $transport = new FakeNotionTransport([]);
        self::assertNull(notion_todo_import($this->record(['id' => 'manuel-1']), $transport));
        self::assertSame([], $transport->requests);
    }

    public function testFailureIsQueuedThenRetried(): void
    {
        $failing = new FakeNotionTransport([
            ['status' => 503, 'json' => ['code' => 'service_unavailable', 'message' => 'try later'], 'error' => ''],
        ]);
        self::assertNull(notion_todo_import($this->record(), $failing));

        $pending = notion_todo_pending_load();
        self::assertCount(1, $pending);
        self::assertSame('fb261103-abcdef12', $pending[0]['record']['id']);
        self::assertStringContainsString('service_unavailable', $pending[0]['last_error']);
        self::assertSame('fb261103-abcdef12', notion_todo_state()['last_ref']);

        $working = new FakeNotionTransport([$this->emptyQuery(), $this->createdPage('page-retry')]);
        self::assertSame(1, notion_todo_flush($working, null, 5));
        self::assertSame([], notion_todo_pending_load());
    }

    public function testBlockerIsPriorityZero(): void
    {
        $transport = new FakeNotionTransport([$this->emptyQuery(), $this->createdPage()]);
        notion_todo_import($this->record(['severity' => 'blocker']), $transport);
        self::assertSame('P0', $transport->requests[1]['body']['properties']['Priorité']['select']['name']);
    }

    public function testClientFeedbackKeepsFullUrlAndKind(): void
    {
        $transport = new FakeNotionTransport([$this->emptyQuery(), $this->createdPage()]);
        notion_todo_import($this->record(['source' => 'client', 'type' => 'idea', 'severity' => '']), $transport);
        $criterion = $transport->requests[1]['body']['properties']['Critère de done']['rich_text'][0]['text']['content'];
        self::assertStringContainsString('Retour client du', $criterion);
        self::assertStringContainsString('https://brocs.arrimage.com/portail/offres.php?boutique=demo', $criterion);
        self::assertSame('P2', $transport->requests[1]['body']['properties']['Priorité']['select']['name']);
    }

    public function testConfigRejectsBadTokenAndNormalisesDatabaseId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        notion_todo_config_save('token-trop-court', self::DATABASE);
    }

    public function testConfigIsWrittenForOwnerOnly(): void
    {
        notion_todo_config_save('ntn_' . str_repeat('b', 40), '19CDE2B8-EEA4-49D7-A883-A24A8CEF20DB');
        $file = NOTION_TODO_SECRETS_DIR . '/notion.json';
        self::assertSame(0600, fileperms($file) & 0777);
        $cfg = notion_todo_config();
        self::assertSame(self::DATABASE, $cfg['database_id']);
        self::assertStringNotContainsString('ntn_' . str_repeat('b', 40), notion_todo_token_preview());
    }
}
