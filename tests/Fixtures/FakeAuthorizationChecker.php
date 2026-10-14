<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Fixtures;

use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationCredentialsNotFoundException;

/** An authorization checker over a fixed map attribute => subject => granted; it records what it was asked. */
final class FakeAuthorizationChecker implements AuthorizationCheckerInterface
{
    /** @var list<array{string, string}> */
    public array $asked = [];

    /** @param array<string, array<string, bool>> $grants */
    public function __construct(private readonly array $grants, private readonly bool $noToken = false, private readonly ?string $noTokenFor = null) {}

    public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
    {
        if (!is_string($attribute) || !is_string($subject)) {
            throw new \InvalidArgumentException('The guard asks with a string attribute and the index name.');
        }
        $this->asked[] = [$attribute, $subject];
        if ($this->noToken || $attribute === $this->noTokenFor) {
            throw new AuthenticationCredentialsNotFoundException();
        }

        return $this->grants[$attribute][$subject] ?? false;
    }
}
