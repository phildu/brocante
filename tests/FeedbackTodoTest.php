<?php

use PHPUnit\Framework\TestCase;

define('TD_TODO_DB', sys_get_temp_dir() . '/brocs-todo-test-' . getmypid() . '.db');
define('TD_TODO_PORTAL_HOST', 'brocs.test');

require_once __DIR__ . '/../includes/feedback-todo.php';

final class FeedbackTodoTest extends TestCase
{
    protected function setUp(): void
    {
        $this->clear();
    }

    protected function tearDown(): void
    {
        $this->clear();
    }

    private function clear(): void
    {
        foreach ([TD_TODO_DB, TD_TODO_DB . '-wal', TD_TODO_DB . '-shm'] as $file) {
            @unlink($file);
        }
    }

    private function record(array $overrides = []): array
    {
        return array_merge([
            'id' => 'fb261103-aaaaaaaa',
            'at' => '2026-11-03T12:58:00+00:00',
            'shop' => 'demo',
            'shop_name' => 'Boutique Démo',
            'source' => 'equipe',
            'type' => 'bug',
            'severity' => 'major',
            'priority' => 'normal',
            'message' => 'La gestion des marges ne recalcule pas la colonne de droite.',
            'page' => '/admin/produits.php',
        ], $overrides);
    }

    private function tasks(): array
    {
        return feedback_todo_db()->query('SELECT * FROM tasks ORDER BY id')->fetchAll();
    }

    public function testImportCreatesTask(): void
    {
        $id = feedback_todo_import($this->record());
        self::assertNotNull($id);

        $tasks = $this->tasks();
        self::assertCount(1, $tasks);
        $task = $tasks[0];
        self::assertSame('feedback:fb261103-aaaaaaaa', $task['source']);
        self::assertSame('todo', $task['status']);
        self::assertSame('boutiques', $task['category']);
        self::assertSame(2, (int) $task['priority']);
        self::assertStringStartsWith('Testeur · Bug — ', $task['title']);
        self::assertStringContainsString('fb261103-aaaaaaaa', $task['detail']);
        self::assertStringContainsString('Boutique Démo', $task['detail']);
        self::assertStringContainsString('/admin/produits.php', $task['detail']);
        self::assertStringContainsString('La gestion des marges', $task['detail']);
        self::assertSame('http://brocs.test/portail/retours.php?focus=fb261103-aaaaaaaa', $task['link']);
    }

    public function testImportIsIdempotent(): void
    {
        $first = feedback_todo_import($this->record());
        $second = feedback_todo_import($this->record());
        self::assertSame($first, $second);
        self::assertCount(1, $this->tasks());
    }

    public function testShortFeedbackIsIgnored(): void
    {
        self::assertNull(feedback_todo_import($this->record(['message' => 'dsddds dssd'])));
        self::assertCount(0, $this->tasks());
    }

    public function testBlockerBecomesPriorityOne(): void
    {
        feedback_todo_import($this->record(['severity' => 'blocker', 'priority' => 'high']));
        self::assertSame(1, (int) $this->tasks()[0]['priority']);
    }

    public function testClientFeedbackIsLabelled(): void
    {
        feedback_todo_import($this->record(['source' => 'client']));
        self::assertStringStartsWith('Client · Bug — ', $this->tasks()[0]['title']);
    }

    public function testManualImportIsNotDuplicated(): void
    {
        $pdo = feedback_todo_db();
        $pdo->prepare('INSERT INTO tasks (title, detail, created_at, updated_at) VALUES (?,?,?,?)')
            ->execute(['Déjà importé', 'Réf. fb261103-aaaaaaaa', '2026-11-03 13:00:00', '2026-11-03 13:00:00']);

        self::assertNotNull(feedback_todo_import($this->record()));
        $tasks = $this->tasks();
        self::assertCount(1, $tasks);
        self::assertSame('Déjà importé', $tasks[0]['title']);
        self::assertSame('', $tasks[0]['source']);
    }

    public function testLegacyTableGetsSourceColumn(): void
    {
        $pdo = new PDO('sqlite:' . TD_TODO_DB);
        $pdo->exec('CREATE TABLE tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            detail TEXT NOT NULL DEFAULT \'\',
            category TEXT NOT NULL DEFAULT \'autre\',
            priority INTEGER NOT NULL DEFAULT 2,
            shop TEXT NOT NULL DEFAULT \'\',
            link TEXT NOT NULL DEFAULT \'\',
            status TEXT NOT NULL DEFAULT \'todo\',
            position INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            done_at TEXT
        )');
        $pdo->exec("INSERT INTO tasks (title, detail, created_at, updated_at) VALUES ('Ancienne tâche', 'ancienne', '2026-11-01 10:00:00', '2026-11-01 10:00:00')");
        unset($pdo);

        feedback_todo_import($this->record());
        $tasks = $this->tasks();
        self::assertCount(2, $tasks);
        self::assertSame('Ancienne tâche', $tasks[0]['title']);
        self::assertSame('feedback:fb261103-aaaaaaaa', $tasks[1]['source']);
    }

    public function testIaKeywordUsesIaCategory(): void
    {
        feedback_todo_import($this->record(['message' => "Les statistiques d'usage de Lia sont absentes."]));
        self::assertSame('ia', $this->tasks()[0]['category']);
    }
}
