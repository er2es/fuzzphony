<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Core\Query\Ast\AllOf;
use Fuzzphony\Core\Query\Ast\Term;
use Fuzzphony\Core\Query\QueryParser;
use Fuzzphony\Engine\Postgres\Sql\TsQueryCompiler;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TsQueryCompilerTest extends TestCase
{
    /** @return iterable<string, array{string, string|null}> */
    public static function cases(): iterable
    {
        yield 'term' => ['Mouse', "'mouse'"];
        yield 'and' => ['wireless mouse', "('wireless' & 'mouse')"];
        yield 'or' => ['mouse | trackpad', "('mouse' | 'trackpad')"];
        yield 'not' => ['mouse -cable', "('mouse' & !'cable')"];
        yield 'phrase' => ['"usb receiver"', "('usb' <-> 'receiver')"];
        yield 'prefix' => ['keyb*', "'keyb':*"];
        yield 'field weight' => ['name:mouse', "'mouse':A"];
        yield 'field prefix weight' => ['brand:logi*', "'logi':*B"];
        yield 'field phrase' => ['brand:"logitech g"', "('logitech':B <-> 'g':B)"];
        yield 'hyphenated word' => ['e-mail', "('e' <-> 'mail')"];
        yield 'unicode' => ['Egér', "'egér'"];
        yield 'groups' => ['(mouse OR trackpad) -cable', "(('mouse' | 'trackpad') & !'cable')"];
        yield 'quotes and stray syntax are neutralised' => ["x' | !'y') --", "('x' | !'y')"];
    }

    #[DataProvider('cases')]
    public function testCompile(string $input, ?string $expected): void
    {
        $root = (new QueryParser())->parse($input)->root;
        self::assertNotNull($root);

        self::assertSame($expected, (new TsQueryCompiler(Indexes::products()))->compile($root));
    }

    public function testUnknownFieldFallsBackToAllFieldsWithAWarning(): void
    {
        $compiler = new TsQueryCompiler(Indexes::products());
        $root = (new QueryParser())->parse('colour:red')->root;
        self::assertNotNull($root);

        self::assertSame("'red'", $compiler->compile($root));
        self::assertSame(['Unknown field "colour"; searched in all fields instead.'], $compiler->warnings());
    }

    public function testLexemesContainOnlyLettersAndDigits(): void
    {
        self::assertSame(['o', 'reilly', '3s', 'ütvefúró'], TsQueryCompiler::lexemes("O'Reilly <3S> Ütvefúró;"));
    }

    public function testAGroupWithOneSurvivingChildReturnsItDirectly(): void
    {
        $compiled = (new TsQueryCompiler(Indexes::products()))->compile(new AllOf([new Term('mouse'), new Term('+++')]));

        self::assertSame("'mouse'", $compiled);
    }

    public function testAGroupWhereEveryChildDropsToNothingCompilesToNull(): void
    {
        $compiled = (new TsQueryCompiler(Indexes::products()))->compile(new AllOf([new Term('+++'), new Term('$$$')]));

        self::assertNull($compiled);
    }
}
