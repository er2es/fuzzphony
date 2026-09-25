<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Postgres;

use Fuzzphony\Engine\Postgres\Sql\Sql;
use PHPUnit\Framework\TestCase;

final class SqlTest extends TestCase
{
    public function testNonFiniteNumbersAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Non-finite number in SQL.');

        Sql::float(NAN);
    }
}
