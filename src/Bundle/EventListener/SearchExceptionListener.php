<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\EventListener;

use Fuzzphony\Core\Exception\ActorRequired;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** @internal Shows a refusal of Fuzzphony as the HTTP status it means (parts 2 and 3 add 429 here). */
final class SearchExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        // Walk the previous-chain: a search that runs inside a Twig template (the Live Component) reaches the
        // kernel wrapped in a Twig\Error\RuntimeError.
        for ($t = $event->getThrowable(); $t !== null; $t = $t->getPrevious()) {
            if ($t instanceof ActorRequired) {
                $event->setThrowable(new AccessDeniedHttpException($t->getMessage(), $t));

                return;
            }
        }
    }
}
