<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bridge\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Fuzzphony\Bridge\Doctrine\OrmSyncListener;
use Fuzzphony\Core\Definition\AttributeDefinitionLoader;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Sync\RefreshDispatcher;
use Fuzzphony\Tests\Fixtures\Doctrine\Article;
use Fuzzphony\Tests\Fixtures\Product;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * OrmSyncListener collects changed ids per index during a flush and hands them to the configured
 * RefreshDispatcher right after it (postFlush). These tests exercise that collection/dispatch logic
 * in isolation, with real IndexDefinitions but a fake dispatcher and a stubbed EntityManager.
 */
final class OrmSyncListenerTest extends TestCase
{
    private IndexDefinition $articleIndex;
    private IndexDefinition $productIndex; // sync mode "trigger", not "orm" -> must be ignored

    protected function setUp(): void
    {
        $loader = new AttributeDefinitionLoader();
        $this->articleIndex = $loader->load(Article::class);
        $this->productIndex = $loader->load(Product::class);
    }

    public function testPostPersistThenPostFlushDispatchesTheId(): void
    {
        $registry = new IndexRegistry([$this->articleIndex]);
        $dispatcher = $this->createMock(RefreshDispatcher::class);
        $dispatcher->expects(self::once())->method('dispatch')->with($this->articleIndex, [1]);

        $listener = new OrmSyncListener($dispatcher, $registry);
        $entity = new Article(1, 'Wireless mouse review');
        $listener->postPersist(new PostPersistEventArgs($entity, $this->entityManagerFor($entity, ['id' => 1])));
        $listener->postFlush(new PostFlushEventArgs(self::createStub(EntityManagerInterface::class)));
    }

    public function testPostUpdateThenPostFlushDispatchesTheId(): void
    {
        $registry = new IndexRegistry([$this->articleIndex]);
        $dispatcher = $this->createMock(RefreshDispatcher::class);
        $dispatcher->expects(self::once())->method('dispatch')->with($this->articleIndex, [2]);

        $listener = new OrmSyncListener($dispatcher, $registry);
        $entity = new Article(2, 'Updated title');
        $listener->postUpdate(new PostUpdateEventArgs($entity, $this->entityManagerFor($entity, ['id' => 2])));
        $listener->postFlush(new PostFlushEventArgs(self::createStub(EntityManagerInterface::class)));
    }

    public function testPreRemoveThenPostFlushDispatchesTheId(): void
    {
        $registry = new IndexRegistry([$this->articleIndex]);
        $dispatcher = $this->createMock(RefreshDispatcher::class);
        $dispatcher->expects(self::once())->method('dispatch')->with($this->articleIndex, [3]);

        $listener = new OrmSyncListener($dispatcher, $registry);
        $entity = new Article(3, 'Removed article');
        $listener->preRemove(new PreRemoveEventArgs($entity, $this->entityManagerFor($entity, ['id' => 3])));
        $listener->postFlush(new PostFlushEventArgs(self::createStub(EntityManagerInterface::class)));
    }

    public function testMultipleChangesInOneFlushAreBatchedIntoOneDispatchCall(): void
    {
        $registry = new IndexRegistry([$this->articleIndex]);
        $dispatcher = $this->createMock(RefreshDispatcher::class);
        $dispatcher->expects(self::once())->method('dispatch')->with($this->articleIndex, [1, 2]);

        $listener = new OrmSyncListener($dispatcher, $registry);
        $a = new Article(1, 'One');
        $b = new Article(2, 'Two');
        $listener->postPersist(new PostPersistEventArgs($a, $this->entityManagerFor($a, ['id' => 1])));
        $listener->postPersist(new PostPersistEventArgs($b, $this->entityManagerFor($b, ['id' => 2])));
        $listener->postFlush(new PostFlushEventArgs(self::createStub(EntityManagerInterface::class)));
    }

    public function testPendingIsClearedAfterEachFlush(): void
    {
        $registry = new IndexRegistry([$this->articleIndex]);
        $dispatcher = $this->createMock(RefreshDispatcher::class);
        $dispatcher->expects(self::once())->method('dispatch')->with($this->articleIndex, [1]);

        $listener = new OrmSyncListener($dispatcher, $registry);
        $entity = new Article(1, 'One');
        $listener->postPersist(new PostPersistEventArgs($entity, $this->entityManagerFor($entity, ['id' => 1])));
        $listener->postFlush(new PostFlushEventArgs(self::createStub(EntityManagerInterface::class)));

        // a second, empty flush must not re-dispatch the same id
        $listener->postFlush(new PostFlushEventArgs(self::createStub(EntityManagerInterface::class)));
    }

    public function testEntitiesNotInTheRegistryAreIgnored(): void
    {
        $registry = new IndexRegistry([$this->articleIndex]);
        $dispatcher = $this->createMock(RefreshDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $listener = new OrmSyncListener($dispatcher, $registry);
        $product = new Product();
        $product->id = 1;
        // Product isn't registered at all here, so it must be a no-op.
        $listener->postPersist(new PostPersistEventArgs($product, $this->entityManagerFor($product, ['id' => 1])));
        $listener->postFlush(new PostFlushEventArgs(self::createStub(EntityManagerInterface::class)));
    }

    public function testEntitiesWithANonOrmSyncModeAreIgnoredEvenWhenRegistered(): void
    {
        $registry = new IndexRegistry([$this->articleIndex, $this->productIndex]);
        $dispatcher = $this->createMock(RefreshDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $listener = new OrmSyncListener($dispatcher, $registry);
        $product = new Product();
        $product->id = 1;
        $listener->postPersist(new PostPersistEventArgs($product, $this->entityManagerFor($product, ['id' => 1])));
        $listener->postFlush(new PostFlushEventArgs(self::createStub(EntityManagerInterface::class)));
    }

    public function testEntitiesWithACompositeIdentifierAreIgnored(): void
    {
        $registry = new IndexRegistry([$this->articleIndex]);
        $dispatcher = $this->createMock(RefreshDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $listener = new OrmSyncListener($dispatcher, $registry);
        $entity = new Article(1, 'Composite');
        $listener->postPersist(new PostPersistEventArgs($entity, $this->entityManagerFor($entity, ['id' => 1, 'other' => 2])));
        $listener->postFlush(new PostFlushEventArgs(self::createStub(EntityManagerInterface::class)));
    }

    /** @param array<string, int|string> $identifier */
    private function entityManagerFor(object $entity, array $identifier): EntityManagerInterface&MockObject
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getIdentifierValues')->with($entity)->willReturn($identifier);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getClassMetadata')->with($entity::class)->willReturn($metadata);

        return $em;
    }
}
