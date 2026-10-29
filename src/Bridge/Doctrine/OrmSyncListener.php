<?php

declare(strict_types=1);

namespace Fuzzphony\Bridge\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\Persistence\ObjectManager;
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
    /** @var array<string, array<string, array{int|string, \WeakReference<ObjectManager>}>> index => id => [id, the manager that changed it] */
    private array $pending = [];

    public function __construct(
        private readonly RefreshDispatcher $dispatcher,
        private readonly IndexRegistry $registry,
    ) {}

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->track($args->getObject(), $args->getObjectManager(), $args->getObjectManager()->getClassMetadata($args->getObject()::class)->getIdentifierValues($args->getObject()));
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->track($args->getObject(), $args->getObjectManager(), $args->getObjectManager()->getClassMetadata($args->getObject()::class)->getIdentifierValues($args->getObject()));
    }

    /** The id is captured before removal because generated ids may be cleared afterwards. */
    public function preRemove(PreRemoveEventArgs $args): void
    {
        $this->track($args->getObject(), $args->getObjectManager(), $args->getObjectManager()->getClassMetadata($args->getObject()::class)->getIdentifierValues($args->getObject()));
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $pending = $this->pending;
        $this->pending = [];
        foreach ($pending as $index => $entries) {
            // a flush that failed never reaches postFlush and Doctrine closes its manager: what that manager collected is
            // dropped here, instead of being refreshed with the next successful flush (of another manager)
            $ids = [];
            foreach ($entries as [$id, $owner]) {
                $manager = $owner->get();
                if ($manager !== null && ($manager === $args->getObjectManager() || !$manager instanceof EntityManagerInterface || $manager->isOpen())) {
                    $ids[] = $id;
                }
            }
            if ($ids !== []) {
                $this->dispatcher->dispatch($this->registry->get($index), $ids);
            }
        }
    }

    /** @param array<string, mixed> $identifier */
    private function track(object $entity, ObjectManager $manager, array $identifier): void
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
        $this->pending[$index->name][(string) $id] = [$id, \WeakReference::create($manager)];
    }
}
