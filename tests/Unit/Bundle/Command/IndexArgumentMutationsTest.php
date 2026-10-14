<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Command;

use Fuzzphony\Bundle\Command\IndexArgument;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

/** IndexArgument::resolve() against a registry of two indexes: the named one, or every one as a list. */
final class IndexArgumentMutationsTest extends TestCase
{
    public function testANamedIndexResolvesToThatIndexAlone(): void
    {
        $fuzzphony = $this->fuzzphony();
        $input = new ArrayInput(['index' => 'shirts'], new InputDefinition([new InputArgument('index', InputArgument::OPTIONAL)]));

        $resolved = IndexArgument::resolve($fuzzphony, $input);

        self::assertSame(['shirts'], array_map(static fn(IndexDefinition $i): string => $i->name, $resolved));
    }

    public function testANamedIndexGivenAsAnOptionResolvesToThatIndexAlone(): void
    {
        $fuzzphony = $this->fuzzphony();
        $input = new ArrayInput(['--index' => 'products'], new InputDefinition([new InputOption('index', null, InputOption::VALUE_REQUIRED)]));

        $resolved = IndexArgument::resolve($fuzzphony, $input);

        self::assertSame(['products'], array_map(static fn(IndexDefinition $i): string => $i->name, $resolved));
    }

    public function testNoIndexOrAnEmptyOneResolvesToEveryIndexAsAList(): void
    {
        $fuzzphony = $this->fuzzphony();
        $definition = new InputDefinition([new InputArgument('index', InputArgument::OPTIONAL)]);

        $missing = IndexArgument::resolve($fuzzphony, new ArrayInput([], $definition));
        $empty = IndexArgument::resolve($fuzzphony, new ArrayInput(['index' => ''], $definition));

        foreach ([$missing, $empty] as $resolved) {
            self::assertSame([0, 1], array_keys($resolved), 'a list, not the registry\'s name-keyed map');
            self::assertSame(['products', 'shirts'], array_map(static fn(IndexDefinition $i): string => $i->name, $resolved));
        }
    }

    private function fuzzphony(): Fuzzphony
    {
        $shirts = IndexDefinition::builder('shirts')->fromQuery('SELECT id, name FROM fz_shirt')->sync('manual')->field('name', 'A')->build();

        return new Fuzzphony(self::createStub(Engine::class), new IndexRegistry([Indexes::products('manual'), $shirts]));
    }
}
