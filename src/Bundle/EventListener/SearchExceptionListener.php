<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\EventListener;

use Fuzzphony\Core\Exception\AccessDenied;
use Fuzzphony\Core\Exception\ActorRequired;
use Fuzzphony\Core\Exception\RateLimited;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/** @internal Shows a refusal of Fuzzphony as the HTTP status it means: 403 (no actor, not granted) and 429 (rate limited, with Retry-After). */
final class SearchExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        // Walk the previous-chain: a search that runs inside a Twig template (the Live Component) reaches the
        // kernel wrapped in a Twig\Error\RuntimeError.
        for ($t = $event->getThrowable(); $t !== null; $t = $t->getPrevious()) {
            if ($t instanceof ActorRequired || $t instanceof AccessDenied) {
                $event->setThrowable(new AccessDeniedHttpException($t->getMessage(), $t));

                return;
            }
            if ($t instanceof RateLimited) {
                $event->setThrowable(new TooManyRequestsHttpException($t->retryAfter, $t->getMessage(), $t));

                return;
            }
        }
    }
}
