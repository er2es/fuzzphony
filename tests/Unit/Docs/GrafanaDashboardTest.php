<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;

final class GrafanaDashboardTest extends TestCase
{
    /** Every metric name this design actually registers, after sanitizing guard()'s operation
     * names (PrometheusMetricsCollector::name() folds spaces and dots to a single underscore). */
    private const array KNOWN = [
        'fuzzphony_search_took_ms',
        'fuzzphony_search_fallback',
        'fuzzphony_search_errors',
        'fuzzphony_explain_errors',
        'fuzzphony_refresh_errors',
        'fuzzphony_source_ids_errors',
        'fuzzphony_orphan_pruning_errors',
        'fuzzphony_rebuild_errors',
        'fuzzphony_queue_size_errors',
        'fuzzphony_queue_processing_errors',
        'fuzzphony_rebuild_failure_record_errors',
        'fuzzphony_highlighting_errors',
        'fuzzphony_queue_depth',
        'fuzzphony_queue_processed',
        'fuzzphony_worker_rebuild_failures',
        'fuzzphony_messenger_refresh_duration_ms',
        'fuzzphony_messenger_refresh_errors',
        'fuzzphony_doctor_check',
    ];

    public function testTheDashboardIsValidJson(): void
    {
        $decoded = self::dashboard();

        self::assertNotEmpty($decoded['panels']);
    }

    public function testEveryPanelQueriesAMetricThisDesignProduces(): void
    {
        $decoded = self::dashboard();

        foreach ($decoded['panels'] as $panel) {
            foreach ($panel['targets'] ?? [] as $target) {
                preg_match_all('/fuzzphony_[a-zA-Z0-9_]+/', $target['expr'], $matches);
                self::assertNotSame([], $matches[0], 'panel "' . $panel['title'] . '" has no fuzzphony_ metric in its query: ' . $target['expr']);
                foreach ($matches[0] as $raw) {
                    $name = (string) preg_replace('/_(sum|count|bucket)$/', '', $raw);
                    self::assertContains($name, self::KNOWN, 'panel "' . $panel['title'] . '" queries an unknown metric: ' . $raw);
                }
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
