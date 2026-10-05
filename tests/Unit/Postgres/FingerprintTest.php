<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Definition\FieldDefinition;
use Fuzzphony\Core\Definition\FilterDefinition;
use Fuzzphony\Core\Definition\FilterType;
use Fuzzphony\Core\Definition\IdType;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\Source;
use Fuzzphony\Core\Definition\SyncMode;
use Fuzzphony\Core\Definition\Synonyms;
use Fuzzphony\Core\Definition\TextConfig;
use Fuzzphony\Core\Definition\TriggerLevel;
use Fuzzphony\Core\Definition\Watch;
use Fuzzphony\Core\Definition\Weight;
use Fuzzphony\Core\Ranking\RankingProfile;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Engine\Postgres\Schema\Fingerprint;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FingerprintTest extends TestCase
{
    /** @return iterable<string, array{\Closure(IndexDefinition): IndexDefinition, bool, bool}> change, changes the DDL, changes the documents */
    public static function changes(): iterable
    {
        yield 'source' => [static fn(IndexDefinition $d): IndexDefinition => $d->withSource(Source::table('fz_other')), true, true];
        yield 'field weight' => [static fn(IndexDefinition $d): IndexDefinition => $d->withFields([new FieldDefinition('name', Weight::C, fuzzy: true), ...array_slice($d->fields, 1)]), true, true];
        yield 'filters' => [static fn(IndexDefinition $d): IndexDefinition => $d->withFilters([]), true, true];
        yield 'text' => [static fn(IndexDefinition $d): IndexDefinition => $d->withText(new TextConfig('german')), true, true];
        yield 'boost' => [static fn(IndexDefinition $d): IndexDefinition => $d->withBoostColumn(null), true, true];
        yield 'recency' => [static fn(IndexDefinition $d): IndexDefinition => $d->withRecencyColumn(null), true, true];
        yield 'id type' => [static fn(IndexDefinition $d): IndexDefinition => $d->withIdType(IdType::String), true, false];
        yield 'watches' => [static fn(IndexDefinition $d): IndexDefinition => $d->withWatches([]), true, false];
        yield 'sync' => [static fn(IndexDefinition $d): IndexDefinition => $d->withSync(SyncMode::Trigger), true, false];
        yield 'trigger level' => [static fn(IndexDefinition $d): IndexDefinition => $d->withTriggerLevel(TriggerLevel::Row), true, false];
        yield 'tenant' => [static fn(IndexDefinition $d): IndexDefinition => $d->withTenant('brand_id'), true, false];
        yield 'thresholds' => [static fn(IndexDefinition $d): IndexDefinition => $d->withThresholds(new Thresholds(minScore: 0.3)), false, false];
        yield 'synonyms' => [static fn(IndexDefinition $d): IndexDefinition => $d->withSynonyms(Synonyms::fromEntries([['tv', 'television']])), false, false];
        yield 'profiles' => [static fn(IndexDefinition $d): IndexDefinition => $d->withProfiles(['default' => new RankingProfile(text: 0.5)]), false, false];
        yield 'highlight flag' => [static fn(IndexDefinition $d): IndexDefinition => $d->withFields([new FieldDefinition('name', Weight::A, fuzzy: true, highlight: false), ...array_slice($d->fields, 1)]), false, false];
    }

    /** @param \Closure(IndexDefinition): IndexDefinition $change */
    #[DataProvider('changes')]
    public function testEachHashFollowsTheParts(\Closure $change, bool $ddl, bool $documents): void
    {
        $original = Indexes::products();
        $changed = $change($original);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', Fingerprint::definition($original));
        self::assertSame(Fingerprint::definition($original), Fingerprint::definition(Indexes::products()), 'stable');
        self::assertSame($ddl, Fingerprint::definition($changed) !== Fingerprint::definition($original));
        self::assertSame($documents, Fingerprint::documents($changed) !== Fingerprint::documents($original));
    }

    public function testTheSharedHashFollowsTheSchemas(): void
    {
        self::assertSame(Fingerprint::shared(new Names()), Fingerprint::shared(new Names('public', 'public')));
        self::assertNotSame(Fingerprint::shared(new Names()), Fingerprint::shared(new Names(schema: 'fuzzphony')));
        self::assertNotSame(Fingerprint::shared(new Names()), Fingerprint::shared(new Names('extensions')));
    }

    /**
     * Each element of the source, field, filter, watch and text tuples on its own: two definitions
     * that differ in that element only.
     *
     * @return iterable<string, array{IndexDefinition, IndexDefinition, bool}> a, b, changes the documents
     */
    public static function tupleElements(): iterable
    {
        $p = Indexes::products();
        $fields = static fn(FieldDefinition $first): IndexDefinition => $p->withFields([$first, ...array_slice($p->fields, 1)]);
        $filters = static fn(FilterDefinition $first): IndexDefinition => $p->withFilters([$first, ...array_slice($p->filters, 1)]);
        $watches = static fn(Watch $first): IndexDefinition => $p->withWatches([$first]);

        yield 'source table' => [$p->withSource(Source::table('fz_a')), $p->withSource(Source::table('fz_b')), true];
        yield 'source id column' => [$p->withSource(Source::table('fz_a')), $p->withSource(Source::table('fz_a', 'uuid')), true];
        yield 'field name' => [$fields(new FieldDefinition('name', Weight::A, fuzzy: true)), $fields(new FieldDefinition('title', Weight::A, fuzzy: true, column: 'name')), true];
        yield 'field column' => [$fields(new FieldDefinition('name', Weight::A, fuzzy: true)), $fields(new FieldDefinition('name', Weight::A, fuzzy: true, column: 'title')), true];
        yield 'field fuzzy' => [$fields(new FieldDefinition('name', Weight::A, fuzzy: true)), $fields(new FieldDefinition('name', Weight::A)), true];
        yield 'filter name' => [$filters(new FilterDefinition('price', FilterType::Int)), $filters(new FilterDefinition('cost', FilterType::Int, 'price')), true];
        yield 'filter column' => [$filters(new FilterDefinition('price', FilterType::Int)), $filters(new FilterDefinition('price', FilterType::Int, 'cost')), true];
        yield 'filter type' => [$filters(new FilterDefinition('price', FilterType::Int)), $filters(new FilterDefinition('price', FilterType::Float)), true];
        yield 'watch table' => [$watches(new Watch('fz_a')), $watches(new Watch('fz_b')), false];
        yield 'watch affected ids' => [$watches(new Watch('fz_a')), $watches(new Watch('fz_a', 'SELECT id FROM fz_product WHERE brand_id = :id')), false];
        yield 'watch key column' => [$watches(new Watch('fz_a')), $watches(new Watch('fz_a', keyColumn: 'uuid')), false];
        yield 'watch columns' => [$watches(new Watch('fz_a')), $watches(new Watch('fz_a', columns: ['name'])), false];
        yield 'sync, both after "sync" in byte order' => [$p->withSync(SyncMode::Queue), $p->withSync(SyncMode::Orm), false];
        yield 'text unaccent' => [$p->withText(new TextConfig('english')), $p->withText(new TextConfig('english', unaccent: false)), true];
    }

    #[DataProvider('tupleElements')]
    public function testEveryElementCounts(IndexDefinition $a, IndexDefinition $b, bool $documents): void
    {
        self::assertNotSame(Fingerprint::definition($a), Fingerprint::definition($b));
        self::assertSame($documents, Fingerprint::documents($a) !== Fingerprint::documents($b));
    }

    /**
     * The hashes are stored in every install's fuzzphony_meta: a change in how they are computed
     * makes every index report "Definition" drift after the upgrade, so it must be deliberate.
     */
    public function testTheHashesAreStable(): void
    {
        self::assertSame('a6de34b30d4564025ae61edcfac1ddca619b9d0cff07e5af387248c7158cfddc', Fingerprint::definition(Indexes::products()));
        self::assertSame('66eeef32e4f48e272df8b2b5dd7949f4486c73d7011c80063f0f1e143b6d49e1', Fingerprint::documents(Indexes::products()));
        self::assertSame('ecb3ea5edac8953024478361977c6dc90a540a7f08587fede5017f970d40d612', Fingerprint::shared(new Names()));
    }
}
