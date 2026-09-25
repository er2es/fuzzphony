<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Command;

use Fuzzphony\Bundle\Command\IndexArgument;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;

final class IndexArgumentTest extends TestCase
{
    public function testResolveThrowsAHelpfulErrorWhenNoIndexIsConfiguredAtAll(): void
    {
        $fuzzphony = new Fuzzphony(self::createStub(Engine::class), new IndexRegistry());
        $input = new ArrayInput([], new InputDefinition([new InputArgument('index', InputArgument::OPTIONAL)]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No search index is configured. Add #[Searchable] to an entity or define one under "fuzzphony.indexes".');

        IndexArgument::resolve($fuzzphony, $input);
    }
}
