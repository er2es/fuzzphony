<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Fixtures;

use Fuzzphony\Core\Attribute\AsIndexDefinition;

/** Carries the attribute but has no build() method. */
#[AsIndexDefinition('no_build')]
final class NotADefinitionClass {}
