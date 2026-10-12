<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Twig;

use Fuzzphony\Bundle\Twig\SearchComponent;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Engine\Suggestions;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Query\SearchQuery;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\Access;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Core\Search\ActorResolver;
use Fuzzphony\Core\Search\SearchGate;
use Fuzzphony\Core\Search\SearchGuard;
use Fuzzphony\Core\Search\SearchResult;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** The Live Component searches and completes as the viewer the server resolves: a restricted viewer gets no completions. */
final class SearchComponentActorTest extends TestCase
{
    /** @var list<SearchQuery> */
    private array $queries = [];
    private int $suggestCalls = 0;

    public function testARestrictedViewerGetsNoCompletionsAndTheEngineIsNotAsked(): void
    {
        $component = $this->component(Access::only(['name']));

        self::assertSame([], $component->getCompletions());
        self::assertSame(0, $this->suggestCalls);
    }

    public function testAViewerWithEveryFieldGetsTheCompletions(): void
    {
        $component = $this->component(Access::all());

        self::assertSame(['wireless keyboard'], $component->getCompletions());
        self::assertSame(1, $this->suggestCalls);
    }

    public function testTheSearchReachesTheEngineLimitedToTheFieldsTheViewerMaySee(): void
    {
        $this->component(Access::only(['name']))->getResult();

        self::assertCount(1, $this->queries);
        self::assertSame(['name'], $this->queries[0]->allowedFields);
    }

    public function testTheActorIsResolvedOncePerCompletionsCall(): void
    {
        $resolver = $this->createMock(ActorResolver::class);
        $resolver->expects(self::once())->method('resolve')->willReturn(new Actor(tenant: 7, user: 'ada'));

        $this->component(Access::all(), $resolver)->getCompletions();
    }

    private function component(Access $access, ?ActorResolver $resolver = null): SearchComponent
    {
        $engine = self::createStubForIntersectionOfInterfaces([Engine::class, Suggestions::class]);
        $engine->method('search')->willReturnCallback(function (IndexDefinition $index, SearchQuery $query): SearchResult {
            $this->queries[] = $query;

            return SearchResult::empty($query->limit, $query->offset);
        });
        $engine->method('suggestWords')->willReturnCallback(function (): array {
            ++$this->suggestCalls;

            return ['keyboard'];
        });
        assert($engine instanceof Engine);

        $guard = self::createStub(SearchGuard::class);
        $guard->method('check')->willReturn($access);
        if ($resolver === null) {
            $resolver = self::createStub(ActorResolver::class);
            $resolver->method('resolve')->willReturn(new Actor(tenant: 7, user: 'ada'));
        }

        $fuzzphony = new Fuzzphony($engine, new IndexRegistry([Indexes::products('manual')]), new SearchGate(guards: [$guard]));
        $component = new SearchComponent($fuzzphony, $resolver);
        $component->index = 'products';
        $component->query = 'wireless key';

        return $component;
    }
}
