<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Query\QueryParser;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Core\Ranking\TypoCurve;
use Fuzzphony\Engine\Postgres\Schema\Names;
use Fuzzphony\Engine\Postgres\Sql\FuzzyQueryCompiler;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/**
 * A restricted viewer's candidates are looked up at the similarity the recheck uses, which judges a prefix one letter shorter: a lookup
 * stricter than the recheck would find a document of the allowed fields only when the text of a field the viewer may not see also
 * matches (what it finds would depend on it). Everyone else's lookup is as it was.
 */
final class RestrictedPrefixSimilarityTest extends TestCase
{
    /** @param list<string>|null $allowed */
    private static function lowest(string $text, ?array $allowed, ?Thresholds $thresholds = null): ?float
    {
        $root = (new QueryParser())->parse($text)->root;
        self::assertNotNull($root);
        $compiler = new FuzzyQueryCompiler(Indexes::products(), $thresholds ?? new Thresholds(), new Names(), [], $allowed);
        $probe = array_values($compiler->probes($root, 100))[0]['similarity'];
        self::assertSame($probe, $compiler->lowestSimilarity($root));

        return $probe;
    }

    public function testARestrictedViewersPrefixIsLookedUpOneLetterShorter(): void
    {
        self::assertSame(TypoCurve::lowest(4, true), self::lowest('mouse*', ['name']));
        self::assertSame(TypoCurve::lowest(5, true), self::lowest('mouse*', null), 'unrestricted: as it was');
        self::assertSame(TypoCurve::lowest(5, true), self::lowest('mouse', ['name']), 'a whole word');
    }

    public function testAFixedSimilarityIsNotTouched(): void
    {
        self::assertSame(0.4, self::lowest('mouse*', ['name'], new Thresholds(fuzzySimilarity: 0.4)));
    }
}
