<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Exception;

use Fuzzphony\Core\Exception\EngineFailure;
use PHPUnit\Framework\TestCase;

final class EngineFailureMutationsTest extends TestCase
{
    public function testAWrappedFailureHasNoErrorCodeOfItsOwn(): void
    {
        $failure = EngineFailure::wrap('search', new \PDOException('boom', 42));

        self::assertSame(0, $failure->getCode(), 'the code of the wrapped exception is not copied, and not invented either');
    }
}
