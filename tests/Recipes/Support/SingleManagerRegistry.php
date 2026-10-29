<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Recipes\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\AbstractManagerRegistry;

/**
 * The ManagerRegistry API Platform's Doctrine filters ask for, around one EntityManager (the application gets
 * it from DoctrineBundle).
 *
 * @internal
 */
final class SingleManagerRegistry extends AbstractManagerRegistry
{
    public function __construct(private readonly EntityManagerInterface $manager)
    {
        $arguments = ['ORM', ['default' => 'connection'], ['default' => 'manager'], 'default', 'default'];
        // persistence 3.x still takes the proxy interface name as a sixth argument, 4.x dropped it
        if (6 === (new \ReflectionMethod(AbstractManagerRegistry::class, '__construct'))->getNumberOfParameters()) {
            $arguments[] = 'Doctrine\Persistence\Proxy';
        }
        parent::__construct(...$arguments);
    }

    protected function getService(string $name): object
    {
        return $this->manager;
    }

    protected function resetService(string $name): void {}
}
