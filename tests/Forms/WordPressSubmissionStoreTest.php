<?php

declare(strict_types=1);

namespace WPStaticSecure\Tests\Forms;

use PHPUnit\Framework\TestCase;
use WPStaticSecure\Forms\Submission;
use WPStaticSecure\Forms\SubmissionStatus;
use WPStaticSecure\Forms\WordPressSubmissionStore;

final class WordPressSubmissionStoreTest extends TestCase
{
    public function testSavesListsAndUpdatesNormalizedSubmissions(): void
    {
        $wpdb = new class {
            public string $prefix = 'wp_';
            /** @var list<array<string, mixed>> */
            public array $rows = [];

            public function insert(string $table, array $data, array $formats): int|false
            {
                $data['id'] = count($this->rows) + 1;
                $this->rows[] = $data;
                return 1;
            }

            public function update(string $table, array $data, array $where, array $formats, array $whereFormats): int|false
            {
                foreach ($this->rows as &$row) {
                    if ((int) $row['id'] === (int) $where['id']) {
                        $row = array_merge($row, $data);
                        return 1;
                    }
                }
                return 0;
            }

            public function prepare(string $query, mixed ...$args): string
            {
                foreach ($args as $arg) {
                    if (str_contains($query, '%s')) {
                        $query = preg_replace('/%s/', "'" . addslashes((string) $arg) . "'", $query, 1) ?? $query;
                    } else {
                        $query = preg_replace('/%d/', (string) (int) $arg, $query, 1) ?? $query;
                    }
                }
                return $query;
            }

            /** @return list<object> */
            public function get_results(string $query): array
            {
                $rows = $this->rows;
                if (preg_match("/WHERE status = '([^']+)'/", $query, $matches) === 1) {
                    $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['status'] === $matches[1]));
                }
                usort($rows, static fn (array $a, array $b): int => (int) $b['id'] <=> (int) $a['id']);
                return array_map(static fn (array $row): object => (object) $row, $rows);
            }

            public function get_row(string $query): ?object
            {
                if (preg_match('/WHERE id = (\d+)/', $query, $matches) !== 1) {
                    return null;
                }
                foreach ($this->rows as $row) {
                    if ((int) $row['id'] === (int) $matches[1]) {
                        return (object) $row;
                    }
                }
                return null;
            }
        };

        $store = new WordPressSubmissionStore($wpdb);
        $store->save(new Submission('contact', ['email' => 'user@example.com', 'message' => 'Hello']));

        $records = $store->list();
        self::assertCount(1, $records);
        self::assertSame('contact', $records[0]->formId());
        self::assertSame(['email' => 'user@example.com', 'message' => 'Hello'], $records[0]->fields());
        self::assertSame(SubmissionStatus::NEW, $records[0]->status());
        self::assertNotSame('', $records[0]->createdAt());

        self::assertTrue($store->updateStatus($records[0]->id(), SubmissionStatus::DONE));
        self::assertSame(SubmissionStatus::DONE, $store->find($records[0]->id())?->status());
        self::assertCount(1, $store->list(SubmissionStatus::DONE));
        self::assertCount(0, $store->list(SubmissionStatus::SPAM));
    }

    public function testRejectsInvalidStatus(): void
    {
        $wpdb = new class {
            public string $prefix = 'wp_';
        };
        $store = new WordPressSubmissionStore($wpdb);

        $this->expectException(\InvalidArgumentException::class);
        $store->updateStatus(1, 'deleted');
    }
}
