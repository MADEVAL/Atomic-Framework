<?php
declare(strict_types=1);

namespace Tests\Engine\Telemetry\View;

use Engine\Atomic\Telemetry\Data\DatabaseSchema;
use Engine\Atomic\Telemetry\Data\DbDiagram;
use Engine\Atomic\Telemetry\View\Fmt;
use Engine\Atomic\Telemetry\View\Templates;
use Engine\Atomic\Telemetry\View\Ui;
use PHPUnit\Framework\TestCase;

final class DatabaseViewTest extends TestCase
{
    private function render(DatabaseSchema $schema): \DOMXPath
    {
        $templates = new Templates(dirname(__DIR__, 4) . '/engine/Atomic/Theme/Builtin/Telemetry/views');
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><body>' . $templates->render('database', new Ui(), $schema) . '</body>');
        libxml_clear_errors();

        return new \DOMXPath($doc);
    }

    private static function column(string $name, string $key = ''): array
    {
        return ['name' => $name, 'type' => 'int(11)', 'nullable' => false, 'default' => null, 'key' => $key, 'extra' => ''];
    }

    private static function schema(): DatabaseSchema
    {
        $fk = ['table' => 'app_orders', 'name' => 'fk_user', 'columns' => ['user_id'], 'ref_table' => 'app_users', 'ref_columns' => ['id'], 'on_update' => 'RESTRICT', 'on_delete' => 'CASCADE'];
        $table = static fn (string $name, array $columns, array $out = [], array $in = []): array => [
            'name' => $name, 'engine' => 'InnoDB', 'rows' => 1200, 'size' => 49152, 'collation' => 'utf8mb4_bin', 'comment' => '',
            'columns' => $columns, 'indexes' => [['name' => 'PRIMARY', 'unique' => true, 'columns' => ['id']]], 'references' => $out, 'referenced_by' => $in,
        ];

        return DatabaseSchema::from_array([
            'available' => true, 'database' => 'shop', 'host' => 'db:3306', 'prefix' => 'app_',
            'tables' => [
                $table('app_users', [self::column('id', 'PRI'), self::column('email', 'UNI')], in: [$fk]),
                $table('app_orders', [self::column('id', 'PRI'), self::column('user_id', 'MUL')], out: [$fk]),
                $table('wp_posts', [self::column('ID', 'PRI')]),
            ],
        ]);
    }

    public function test_tab_opens_on_the_app_tables_with_a_summary_per_scope(): void
    {
        $xpath = $this->render(self::schema());

        self::assertSame('app', $xpath->query('//*[@data-list]')->item(0)->getAttribute('data-default'));
        self::assertSame(['2 tables', '3 tables'], array_map(static fn (\DOMElement $b): string => $b->textContent, iterator_to_array($xpath->query('//*[contains(@class, "summary")]/b'))));
        self::assertSame('summary hide', $xpath->query('//*[@data-when="all"][contains(@class, "summary")]')->item(0)->getAttribute('class'));
        self::assertSame('all', $xpath->query('//*[@data-row="wp_posts"]')->item(0)->getAttribute('data-keys'));
        self::assertSame('app_users id email', $xpath->query('//*[@data-row="app_users"]')->item(0)->getAttribute('data-text'));
        // Scope counts don't follow the search.
        self::assertFalse($xpath->query('//*[@data-filter="app"]/span')->item(0)->hasAttribute('data-count'));
        self::assertSame('~1,200', $xpath->query('//*[@data-row="app_users"]/*[contains(@class, "db-rows")]')->item(0)->textContent);
        self::assertSame('48 KB', Fmt::bytes(49152));
        self::assertSame('1.5 GB', Fmt::bytes(1610612736));
    }

    public function test_drawer_links_related_tables_and_draws_the_diagram(): void
    {
        $xpath = $this->render(self::schema());
        $users = self::schema()->tables[0];

        self::assertSame('[["L|app_orders|user_id","C|id"]]', $xpath->query('//*[@data-row="app_users"]//*[@data-links]')->item(0)->getAttribute('data-links'));
        self::assertSame('app_orders', $xpath->query('//*[@data-row="app_users"]//button[@data-select]')->item(0)->getAttribute('data-select'));
        self::assertSame([['id', 'PK'], ['email', '']], array_map(static fn (array $shown): array => [$shown[0]->name, $shown[1]], DbDiagram::of($users)->shown));
        self::assertSame("app_orders\n  id int(11) NOT NULL\n  user_id int(11) NOT NULL\n\nIndexes\n  PRIMARY UNIQUE (id)\n\nForeign keys\n  fk_user (user_id) -> app_users (id) ON DELETE CASCADE ON UPDATE RESTRICT", self::schema()->tables[1]->schema_text());
    }

    public function test_unavailable_schema_says_why(): void
    {
        $xpath = $this->render(DatabaseSchema::from_array(['available' => false, 'reason' => 'No connection.']));

        self::assertSame('No connection.', $xpath->query('//*[@class="empty"]')->item(0)->textContent);
    }
}
