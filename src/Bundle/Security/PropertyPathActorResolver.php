<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\Security;

use Fuzzphony\Core\Exception\InvalidArgument;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Core\Search\ActorResolver;
use Symfony\Component\PropertyAccess\Exception\ExceptionInterface as PropertyAccessException;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * @internal The actor read from the Security user with property paths (fuzzphony.actor.tenant: account.id,
 * fuzzphony.actor.user: userIdentifier). No token, or a user without the property: that part is null.
 */
final readonly class PropertyPathActorResolver implements ActorResolver
{
    public function __construct(
        private TokenStorageInterface $tokens,
        private PropertyAccessorInterface $accessor,
        private ?string $tenantPath,
        private ?string $userPath,
    ) {}

    public function resolve(): Actor
    {
        $user = $this->tokens->getToken()?->getUser();
        if ($user === null) {
            return Actor::anonymous();
        }
        $tenant = $this->read($user, $this->tenantPath, 'fuzzphony.actor.tenant');
        $name = $this->read($user, $this->userPath, 'fuzzphony.actor.user');

        return new Actor($tenant, $name === null ? null : (string) $name);
    }

    private function read(object $user, ?string $path, string $setting): int|string|null
    {
        if ($path === null) {
            return null;
        }
        try {
            $value = $this->accessor->getValue($user, $path);
        } catch (PropertyAccessException) {
            return null;
        }
        if ($value === null || is_int($value) || is_string($value)) {
            return $value;
        }
        if (is_scalar($value) && !is_bool($value)) {
            return (string) $value;
        }

        throw new InvalidArgument(sprintf('%s "%s" must read a string or an integer from the user, got %s.', $setting, $path, get_debug_type($value)));
    }
}
