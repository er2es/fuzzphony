<?php

declare(strict_types=1);

namespace Fuzzphony\Bridge\Doctrine;

use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Fuzzphony\Core\Definition\SyncMode;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Sync\RefreshDispatcher;

/**
 * Sync mode "orm": collects changed entity ids during a flush and hands them to a RefreshDispatcher
 * right after it: immediately (default) or asynchronously through Symfony Messenger.
 * (Joined data changed by other entities is not seen; use "queue" mode with watches for that.)
 */
final class OrmSyncListener
{
    /** @var array<string, array<string, int|string>> index => id => id */
    private array $pending = [];

    public function __construct(
        private readonly RefreshDispatcher $dispatcher,
        private readonly IndexRegistry $registry,
    ) {}

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->track($args->getObject(), $args->getObjectManager()->getClassMetadata($args->getObject()::class)->getIdentifierValues($args->getObject()));
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->track($args->getObject(), $args->getObjectManager()->getClassMetadata($args->getObject()::class)->getIdentifierValues($args->getObject()));
    }

    /** The id is captured before removal because generated ids may be cleared afterwards. */
    public function preRemove(PreRemoveEventArgs $args): void
    {
        $this->track($args->getObject(), $args->getObjectManager()->getClassMetadata($args->getObject()::class)->getIdentifierValues($args->getObject()));
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $pending = $this->pending;
        $this->pending = [];
        foreach ($pending as $index => $ids) {
            $this->dispatcher->dispatch($this->registry->get($index), array_values($ids));
        }
    }

    /** @param array<string, mixed> $identifier */
    private function track(object $entity, array $identifier): void
    {
        $class = $entity::class;
        if (!$this->registry->has($class) || count($identifier) !== 1) {
            return;
        }
        $index = $this->registry->get($class);
        $id = reset($identifier);
        if ($index->sync !== SyncMode::Orm || !(is_int($id) || is_string($id) || $id instanceof \Stringable)) {
            return;
        }
        $id = $id instanceof \Stringable ? (string) $id : $id;
        $this->pending[$index->name][(string) $id] = $id;
    }
}
