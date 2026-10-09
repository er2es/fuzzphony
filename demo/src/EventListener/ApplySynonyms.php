<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\SynonymStore;
use Fuzzphony\Core\Fuzzphony;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/** Every request starts with the synonyms the demo keeps in its database (see SynonymStore). */
final readonly class ApplySynonyms
{
    public function __construct(private SynonymStore $store, private Fuzzphony $fuzzphony) {}

    #[AsEventListener]
    public function __invoke(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->store->apply($this->fuzzphony);
        }
    }
}
