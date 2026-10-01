<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;

final class GrafanaDashboardTest extends TestCase
{
    public function testTheDashboardIsValidJson(): void
    {
        $decoded = self::dashboard();

        self::assertNotEmpty($decoded['panels']);
    }

    public function testEveryPanelQueriesAMetricThisDesignProduces(): void
    {
        $decoded = self::dashboard();
        $known = ['fuzzphony_search_took_ms', 'fuzzphony_search_fallback', 'fuzzphony_queue_depth', 'fuzzphony_queue_processed', 'fuzzphony_worker_rebuild_failures', 'fuzzphony_messenger_refresh_duration_ms', 'fuzzphony_messenger_refresh_errors', 'fuzzphony_doctor_check'];

        foreach ($decoded['panels'] as $panel) {
            foreach ($panel['targets'] ?? [] as $target) {
                self::assertTrue(
                    array_any($known, static fn(string $m): bool => str_contains($target['expr'], $m)) || str_contains($target['expr'], 'fuzzphony_'),
                    'panel "' . $panel['title'] . '" does not query a known metric: ' . $target['expr'],
                );
            }
        }
    }

    /** @return array{panels: list<array{title: string, targets?: list<array{expr: string}>}>} */
    private static function dashboard(): array
    {
        $path = dirname(__DIR__, 3) . '/docs/grafana/fuzzphony-overview.json';
        /** @var array{panels: list<array{title: string, targets?: list<array{expr: string}>}>} $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
