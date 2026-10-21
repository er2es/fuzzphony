<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Query\Ast\AnyOf;
use Fuzzphony\Core\Query\Ast\Node;
use Fuzzphony\Core\Query\Ast\Term;
use Fuzzphony\Core\Query\QueryParser;
use Fuzzphony\Core\Ranking\Thresholds;
use Fuzzphony\Engine\Postgres\Sql\FuzzyQueryCompiler;
use Fuzzphony\Engine\Postgres\TypoTerm;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/**
 * The words the highlighter marks by typo tolerance are the leaves the trigram branch has: no more, no fewer.
 * Fields of Indexes::products(): name (A, fuzzy), brand (B, fuzzy), description (D, not fuzzy).
 */
final class FuzzyQueryCompilerTypoTermsTest extends TestCase
{
    public function testEveryPositiveWordIsATermInOrder(): void
    {
        self::assertEquals([new TypoTerm('wireles'), new TypoTerm('mouse')], self::terms('wireles mouse'));
        self::assertSame(1, self::terms('wireles')[0]->words);
    }

    public function testAnExcludedWordIsNeverATerm(): void
    {
        self::assertEquals([new TypoTerm('mouse')], self::terms('mouse -cable'));
        self::assertEquals([new TypoTerm('mouse')], self::terms('mouse -(cable | wired)'));
        self::assertEquals([new TypoTerm('mouse')], self::terms('-cable mouse'));
    }

    public function testAPhraseIsOneTermOfSeveralWords(): void
    {
        $terms = self::terms('"noise cancelling"');

        self::assertEquals([new TypoTerm('noise cancelling')], $terms);
        self::assertSame(2, $terms[0]->words);
    }

    public function testAPrefixKeepsItsFlag(): void
    {
        self::assertEquals([new TypoTerm('hedph', prefix: true)], self::terms('hedph*'));
    }

    public function testAScopedWordKeepsItsFieldAnUnknownFieldSearchesEverywhere(): void
    {
        self::assertEquals([new TypoTerm('hedphones', field: 'name')], self::terms('name:hedphones'));
        self::assertEquals([new TypoTerm('mouse')], self::terms('colour:mouse'));
    }

    public function testAWordTooShortOrScopedToAFieldThatIsNotTypoTolerantIsNotATerm(): void
    {
        self::assertSame([], self::terms('ab'));
        self::assertSame([], self::terms('description:mouse'), 'description is not typo-tolerant');
        self::assertEquals([new TypoTerm('mouse')], self::terms('description:mouse mouse'));
    }

    public function testAStopWordIsNotATerm(): void
    {
        $compiler = new FuzzyQueryCompiler(Indexes::products(), new Thresholds());

        self::assertEquals([new TypoTerm('mouse')], $compiler->typoTerms(self::parse('the mouse'), ["'the'"]));
    }

    public function testAnAlternativeOfASynonymIsATermAsWell(): void
    {
        $root = new AnyOf([new Term('tv'), new Term('television')]);

        self::assertEquals([new TypoTerm('television')], (new FuzzyQueryCompiler(Indexes::products(), new Thresholds()))->typoTerms($root), '"tv" is too short for typo tolerance, the alternative is not');
    }

    public function testARestrictedViewerHasTermsOnlyForItsTypoTolerantFields(): void
    {
        $root = self::parse('mouse name:hedphones brand:razr');

        self::assertEquals(
            [new TypoTerm('mouse'), new TypoTerm('hedphones', field: 'name')],
            (new FuzzyQueryCompiler(Indexes::products(), new Thresholds(), allowedFields: ['name']))->typoTerms($root),
            'brand is hidden: its word is not looked for; mouse is looked for in the field it may see',
        );
        self::assertSame([], (new FuzzyQueryCompiler(Indexes::products(), new Thresholds(), allowedFields: ['description']))->typoTerms($root), 'no typo-tolerant field is visible');
        self::assertSame([], (new FuzzyQueryCompiler(Indexes::products(), new Thresholds(), allowedFields: []))->typoTerms($root));
    }

    /** @return list<TypoTerm> */
    private static function terms(string $query): array
    {
        return (new FuzzyQueryCompiler(Indexes::products(), new Thresholds()))->typoTerms(self::parse($query));
    }

    private static function parse(string $input): Node
    {
        $root = (new QueryParser())->parse($input)->root;
        self::assertNotNull($root);

        return $root;
    }
}
