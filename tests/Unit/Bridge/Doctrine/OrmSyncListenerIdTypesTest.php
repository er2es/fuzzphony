<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bridge\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Fuzzphony\Bridge\Doctrine\OrmSyncListener;
use Fuzzphony\Core\Definition\AttributeDefinitionLoader;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Sync\RefreshDispatcher;
use Fuzzphony\Tests\Fixtures\Doctrine\Article;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Which identifier values the ORM listener hands to the dispatcher, and in what form. */
final class OrmSyncListenerIdTypesTest extends TestCase
{
    /** @return iterable<string, array{mixed, list<int|string>|null}> */
    public static function identifiers(): iterable
    {
        yield 'an int' => [5, [5]];
        yield 'a string' => ['abc', ['abc']];
        yield 'a Stringable object (a uuid value object) becomes its string' => [new class implements \Stringable {
            public function __toString(): string
            {
                return 'uuid-1';
            }
        }, ['uuid-1']];
        yield 'a float is not an id' => [1.5, null];
        yield 'an object that is not Stringable is not an id' => [new \stdClass(), null];
        yield 'null is not an id' => [null, null];
    }

    /** @param list<int|string>|null $dispatched */
    #[DataProvider('identifiers')]
    public function testOnlyIntStringAndStringableIdentifiersAreDispatched(mixed $identifier, ?array $dispatched): void
    {
        $index = (new AttributeDefinitionLoader())->load(Article::class);
        $dispatcher = $this->createMock(RefreshDispatcher::class);
        if ($dispatched === null) {
            $dispatcher->expects(self::never())->method('dispatch');
        } else {
            // identical, not merely equal: a Stringable id arrives as its string
            $dispatcher->expects(self::once())->method('dispatch')->willReturnCallback(
                static function (mixed $dispatchedIndex, array $ids) use ($index, $dispatched): void {
                    self::assertSame($index, $dispatchedIndex);
                    self::assertSame($dispatched, $ids);
                },
            );
        }
        $entity = new Article(1, 'One');
        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('getIdentifierValues')->willReturn(['id' => $identifier]);
        $em = self::createStub(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturn($metadata);

        $listener = new OrmSyncListener($dispatcher, new IndexRegistry([$index]));
        $listener->postPersist(new PostPersistEventArgs($entity, $em));
        $listener->postFlush(new PostFlushEventArgs(self::createStub(EntityManagerInterface::class)));
    }
}
