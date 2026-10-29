<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Recipes\MultiTenant;

use Symfony\Component\Security\Core\User\UserInterface;

// region: user
/** The Security user of the application: it knows the account (the tenant) it belongs to (`account.id` in the config). */
final class AppUser implements UserInterface
{
    /**
     * @param non-empty-string $username
     * @param list<string>     $roles
     */
    public function __construct(
        public readonly Account $account,
        private readonly string $username,
        private readonly array $roles = ['ROLE_USER'],
    ) {}

    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    /** Symfony 7.4's UserInterface still requires it (removed in 8): nothing to erase here. */
    public function eraseCredentials(): void {}
}
// endregion
