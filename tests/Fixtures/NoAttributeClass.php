<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Fixtures;

use Fuzzphony\Core\Definition\IndexBuilder;

/** Has build() but no attribute. */
final class NoAttributeClass
{
    public function build(IndexBuilder $b): void {}
}
