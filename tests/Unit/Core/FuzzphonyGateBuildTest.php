<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core;

use Fuzzphony\Core\Analytics\IpMode;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\InvalidArgument;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Search\Access;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Core\Search\ActorResolver;
use Fuzzphony\Core\Search\SearchGuard;
use Fuzzphony\Tests\Fixtures\Indexes;
use Fuzzphony\Tests\Unit\Core\Search\RecordingSearchLog;
use Fuzzphony\Tests\Unit\Postgres\RecordingConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Fuzzphony::postgres(): which settings build a search gate, and the defaults it passes on. */
final class FuzzphonyGateBuildTest extends TestCase
{
    private static function connection(): RecordingConnection
    {
        return new RecordingConnection(static fn(): mixed => null);
    }

    /** @return iterable<string, array{\Closure(RecordingConnection): Fuzzphony, bool}> */
    public static function settings(): iterable
    {
        $guard = new class implements SearchGuard {
            public function check(IndexDefinition $index, Actor $actor, bool $charge = true): Access
            {
                return Access::all();
            }
        };
        $resolver = new class implements ActorResolver {
            public function resolve(): Actor
            {
                return Actor::anonymous();
            }
        };
        $indexes = [Indexes::products()];

        yield 'nothing' => [static fn(RecordingConnection $c): Fuzzphony => Fuzzphony::postgres($c, $indexes), false];
        yield 'a guard' => [static fn(RecordingConnection $c): Fuzzphony => Fuzzphony::postgres($c, $indexes, guards: [$guard]), true];
        yield 'the search log' => [static fn(RecordingConnection $c): Fuzzphony => Fuzzphony::postgres($c, $indexes, searchLog: true), true];
        yield 'a log of its own' => [static fn(RecordingConnection $c): Fuzzphony => Fuzzphony::postgres($c, $indexes, log: new RecordingSearchLog()), true];
        yield 'a redaction' => [static fn(RecordingConnection $c): Fuzzphony => Fuzzphony::postgres($c, $indexes, redact: ['email']), true];
        yield 'an ip mode' => [static fn(RecordingConnection $c): Fuzzphony => Fuzzphony::postgres($c, $indexes, ipMode: IpMode::Full), true];
        yield 'an actor resolver' => [static fn(RecordingConnection $c): Fuzzphony => Fuzzphony::postgres($c, $indexes, actors: $resolver), true];
        yield 'system searches switched off' => [static fn(RecordingConnection $c): Fuzzphony => Fuzzphony::postgres($c, $indexes, allowSystem: false), true];
    }

    /** @param \Closure(RecordingConnection): Fuzzphony $build */
    #[DataProvider('settings')]
    public function testTheGateExistsExactlyWhenASettingNeedsIt(\Closure $build, bool $gate): void
    {
        $fuzzphony = $build(self::connection());

        self::assertSame($gate, (new \ReflectionProperty(Fuzzphony::class, 'gate'))->getValue($fuzzphony) !== null);
    }

    public function testARedactionSettingBuildsARedactorAndNoneBuildsNone(): void
    {
        $gateOf = static function (Fuzzphony $f): object {
            $gate = (new \ReflectionProperty(Fuzzphony::class, 'gate'))->getValue($f);
            self::assertIsObject($gate);

            return $gate;
        };
        $redactor = static fn(object $gate): mixed => (new \ReflectionProperty($gate, 'redactor'))->getValue($gate);

        self::assertNotNull($redactor($gateOf(Fuzzphony::postgres(self::connection(), [Indexes::products()], redact: ['email']))));
        self::assertNull($redactor($gateOf(Fuzzphony::postgres(self::connection(), [Indexes::products()], searchLog: true))));
    }

    public function testTheSearchLogIsOffAndKeptNinetyDaysByDefault(): void
    {
        $fuzzphony = Fuzzphony::postgres(self::connection(), [Indexes::products()]);
        $engine = (new \ReflectionProperty(Fuzzphony::class, 'engine'))->getValue($fuzzphony);
        self::assertIsObject($engine);

        self::assertSame(90, (new \ReflectionProperty($engine, 'searchLogRetentionDays'))->getValue($engine));
        self::assertFalse((new \ReflectionProperty($engine, 'searchLogEnabled'))->getValue($engine));
        $this->expectException(InvalidArgument::class);
        $fuzzphony->logSearch('products', 'mouse', 1, actor: new Actor(user: 'ada'));
    }

    public function testASearchRecordedAfterTheFactIsNotFuzzyUnlessSaid(): void
    {
        $log = new RecordingSearchLog();
        $fuzzphony = Fuzzphony::postgres(self::connection(), [Indexes::products()], log: $log);

        $logged = $fuzzphony->logSearch('products', 'mouse', 1, actor: new Actor(user: 'ada'));

        self::assertFalse($logged->usedFuzzy);
        self::assertFalse($log->records[0]->usedFuzzy);
    }
}
