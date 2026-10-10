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

    public function testASuggestionIsOfferedAndSearchedWhenChosen(): void
    {
        $component = $this->createLiveComponent('Fuzzphony:Search', ['index' => 'articles', 'query' => 'mose']);

        $html = (string) $component->render();
        self::assertStringContainsString('fuzzphony-search__did-you-mean', $html);
        self::assertStringContainsString('>mouse</button>', $html);

        $component->call('useSuggestion');
        $after = (string) $component->render();

        self::assertStringNotContainsString('fuzzphony-search__did-you-mean', $after, 'mouse is a word of the index');
        self::assertStringContainsString('data-id="1"', $after);
    }

    public function testNoSuggestionForAWordTheIndexKnows(): void
    {
        $component = $this->createLiveComponent('Fuzzphony:Search', ['index' => 'articles', 'query' => 'mouse']);

        self::assertStringNotContainsString('fuzzphony-search__did-you-mean', (string) $component->render());
    }

    public function testTheWordBeingTypedIsCompletedAndTheCompletionIsSearchedWhenChosen(): void
    {
        $component = $this->createLiveComponent('Fuzzphony:Search', ['index' => 'articles', 'query' => 'key']);

        $html = (string) $component->render();
        self::assertStringContainsString('fuzzphony-search__completions', $html);
        self::assertStringContainsString('data-live-text-param="keyboard"', $html);

        $component->call('useCompletion', ['text' => 'keyboard']);

        self::assertStringContainsString('data-id="3"', (string) $component->render());
    }

    public function testCompletionsCanBeSwitchedOffAndAreEscaped(): void
    {
        $off = $this->createLiveComponent('Fuzzphony:Search', ['index' => 'articles', 'query' => 'key', 'suggestions' => 0]);
        self::assertStringNotContainsString('fuzzphony-search__completions', (string) $off->render());

        $html = (string) $this->createLiveComponent('Fuzzphony:Search', ['index' => 'articles', 'query' => '<b>key'])->render();
        self::assertStringNotContainsString('<b>keyboard', $html, 'the completed text is plain text and is escaped');
    }

    public function testFacetsListTheValuesWithTheirCountsAndChoosingOneNarrowsTheSearch(): void
    {
        PostgresTestCase::connect()->execute('UPDATE fz_article SET published = false WHERE id = 2');
        $fuzzphony = self::getContainer()->get(Fuzzphony::class);
        self::assertInstanceOf(Fuzzphony::class, $fuzzphony);
        $fuzzphony->refresh('articles', [2]);
        $component = $this->createLiveComponent('Fuzzphony:Search', ['index' => 'articles', 'query' => 'mouse', 'facets' => 'published']);

        $html = (string) $component->render();
        self::assertStringContainsString('data-facet="published"', $html);
        self::assertStringContainsString('data-id="1"', $html);
        self::assertStringContainsString('data-id="2"', $html);

        $component->call('toggleFacet', ['filter' => 'published', 'value' => '1']);
        $narrowed = (string) $component->render();
        self::assertStringContainsString('data-id="1"', $narrowed);
        self::assertStringNotContainsString('data-id="2"', $narrowed, 'the unpublished article is filtered out');
        self::assertStringContainsString('aria-pressed="true"', $narrowed);
        self::assertStringContainsString('data-live-value-param="0"', $narrowed, 'the facet still offers the other value');

        $component->call('toggleFacet', ['filter' => 'published', 'value' => '1']);
        self::assertStringContainsString('data-id="2"', (string) $component->render(), 'choosing it again lifts it');
    }

    public function testOnlyTheFiltersListedAsFacetsCanBeChosen(): void
    {
        $component = $this->createLiveComponent('Fuzzphony:Search', ['index' => 'articles', 'query' => 'mouse']);

        $component->call('toggleFacet', ['filter' => 'published', 'value' => '0']);

        self::assertStringContainsString('data-id="1"', (string) $component->render(), 'not a facet of this component: ignored');
    }
}
