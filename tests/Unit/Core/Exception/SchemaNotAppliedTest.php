<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Exception;

use Fuzzphony\Core\Exception\SchemaNotApplied;
use PHPUnit\Framework\TestCase;

final class SchemaNotAppliedTest extends TestCase
{
    private static function pdo(string $state, string $message): \PDOException
    {
        $e = new \PDOException($message);
        $e->errorInfo = [$state, 7, $message];
        (new \ReflectionProperty(\PDOException::class, 'code'))->setValue($e, $state);

        return $e;
    }

    public function testTheMessageNamesTheColumnAndTheRemedy(): void
    {
        $e = SchemaNotApplied::missing(['tsv_capped', 'actor_ip']);

        self::assertSame('The Fuzzphony schema is not up to date (missing: tsv_capped, actor_ip). Run: bin/console fuzzphony:schema --apply', $e->getMessage());
        self::assertInstanceOf(\RuntimeException::class, $e);
    }

    public function testAnUndefinedColumnOfTheSchemaIsRecognisedAnywhereInTheChain(): void
    {
        $inner = self::pdo('42703', 'SQLSTATE[42703]: Undefined column: 7 ERROR:  column s.tsv_capped does not exist');
        $outer = new \RuntimeException('wrapped', 0, $inner);

        $found = SchemaNotApplied::from($outer);

        self::assertNotNull($found);
        self::assertStringContainsString('missing: tsv_capped', $found->getMessage());
        self::assertSame($outer, $found->getPrevious());
        self::assertStringContainsString('missing: actor_ip', (string) SchemaNotApplied::from(self::pdo('42703', 'column "actor_ip" of relation "x" does not exist'))?->getMessage());
    }

    public function testAnyOtherFailureIsLeftAlone(): void
    {
        self::assertNull(SchemaNotApplied::from(self::pdo('42703', 'column "price" does not exist')), 'a column of the source is not the schema');
        self::assertNull(SchemaNotApplied::from(self::pdo('42P01', 'relation "tsv_capped" does not exist')), 'another SQLSTATE');
        self::assertNull(SchemaNotApplied::from(new \RuntimeException('column tsv_capped does not exist')), 'no SQLSTATE');
    }
}
