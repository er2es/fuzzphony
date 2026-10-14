<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Registry;

use Fuzzphony\Bridge\Doctrine\DoctrineIndexDiscovery;
use Fuzzphony\Bundle\Registry\RegistryFactory;
use Fuzzphony\Tests\Integration\Bridge\DoctrineTestCase;
use PHPUnit\Framework\TestCase;

final class RegistryFactoryMutationsTest extends TestCase
{
    public function testTheDiscoveredIndexesAreInTheRegistryAndYamlOverridesThem(): void
    {
        $discovery = new DoctrineIndexDiscovery(DoctrineTestCase::entityManager());

        $discovered = RegistryFactory::create([], $discovery);
        $overridden = RegistryFactory::create(['articles' => ['sync' => 'manual']], $discovery);

        self::assertTrue($discovered->has('articles'), 'the #[Searchable] entity is found');
        self::assertSame('orm', $discovered->get('articles')->sync->value);
        self::assertSame('manual', $overridden->get('articles')->sync->value, 'YAML overrides a discovered index');
        self::assertFalse(RegistryFactory::create([])->has('articles'), 'without discovery there is nothing to find');
    }
}
