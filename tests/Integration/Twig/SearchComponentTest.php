<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration\Twig;

use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Tests\Integration\Bridge\DoctrineTestCase;
use Fuzzphony\Tests\Integration\PostgresTestCase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * SearchComponent (<twig:Fuzzphony:Search />) rendered through a real Symfony kernel with the real
 * UX Live Component machinery, against a real Postgres-backed Fuzzphony index.
 */
final class SearchComponentTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    protected static function getKernelClass(): string
    {
        return LiveComponentTestKernel::class;
    }

    protected function setUp(): void
    {
        $connection = PostgresTestCase::connect();
        DoctrineTestCase::createArticleTable($connection);
        foreach ([
            [1, 'Wireless mouse review', 'A great mouse for everyday use'],
            [2, 'Gaming mouse RGB review', 'Fast mouse with RGB lighting'],
            [3, 'Keyboard buying guide', 'How to choose a mechanical keyboard'],
        ] as [$id, $title, $body]) {
            $connection->execute('INSERT INTO fz_article (id, title, body) VALUES (:id, :title, :body)', ['id' => $id, 'title' => $title, 'body' => $body]);
        }

        self::bootKernel();
        $fuzzphony = self::getContainer()->get(Fuzzphony::class);
        self::assertInstanceOf(Fuzzphony::class, $fuzzphony);
        $fuzzphony->schema()->apply($connection);
        $fuzzphony->reindex('articles');
    }

    public function testMountsAndRendersResultsForTheInitialQuery(): void
    {
        $component = $this->createLiveComponent('Fuzzphony:Search', ['index' => 'articles', 'query' => 'mouse', 'highlight' => 'title']);

        $html = (string) $component->render();

        self::assertStringContainsString('fuzzphony-search__hits', $html);
        self::assertStringContainsString('data-id="1"', $html);
        self::assertStringContainsString('data-id="2"', $html);
        self::assertStringNotContainsString('data-id="3"', $html, 'the keyboard article does not mention "mouse"');
        self::assertStringContainsString('<mark>', $html, 'the highlighted title snippet must be rendered');
    }

    public function testUpdatingTheQueryReRendersDifferentResults(): void
    {
        $component = $this->createLiveComponent('Fuzzphony:Search', ['index' => 'articles', 'query' => 'mouse']);
        self::assertStringContainsString('data-id="1"', (string) $component->render());

        $component->set('query', 'keyboard');
        $html = (string) $component->render();

        self::assertStringContainsString('data-id="3"', $html);
        self::assertStringNotContainsString('data-id="1"', $html);
    }

    public function testAnEmptyQueryRendersNoResultsBlock(): void
    {
        $component = $this->createLiveComponent('Fuzzphony:Search', ['index' => 'articles', 'query' => '']);

        self::assertStringNotContainsString('fuzzphony-search__hits', (string) $component->render());
    }

    public function testAQueryWithoutMatchesShowsAnEmptyState(): void
    {
        $component = $this->createLiveComponent('Fuzzphony:Search', ['index' => 'articles', 'query' => 'nonexistentxyzquery']);

        self::assertStringContainsString('No results.', (string) $component->render());
    }
}
