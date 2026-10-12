<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * One of the five viewers of the Security page. They are fixed: a name, the roles and the company that Fuzzphony reads
 * as the tenant (fuzzphony.actor.tenant: company). Nothing about a viewer comes from the request.
 */
final readonly class DemoUser implements UserInterface
{
    /** What the page shows for a visitor nobody has signed in as (no user object exists for it). */
    public const string ANONYMOUS = 'anonymous';

    /** @param list<string> $roles */
    private function __construct(
        private string $id,
        public string $name,
        private array $roles,
        private ?int $company,
        public string $companyName,
    ) {}

    /** @return array<string, self> every viewer that can be chosen, by identifier */
    public static function all(): array
    {
        return [
            'ada' => new self('ada', 'Ada', ['ROLE_USER'], 1, 'Acme'),
            'hana' => new self('hana', 'Hana (HR)', ['ROLE_USER', 'ROLE_HR'], 1, 'Acme'),
            'bob' => new self('bob', 'Bob', ['ROLE_USER'], 2, 'Globex'),
            // signed in, with a company (so the tenant check passes) but without ROLE_USER: the AccessDenied example
            'eve' => new self('eve', 'Eve (guest)', [], 1, 'Acme'),
        ];
    }

    public static function find(string $id): ?self
    {
        return self::all()[$id] ?? null;
    }

    /** The company id: the tenant of the actor (the property path `company` of fuzzphony.actor.tenant). */
    public function getCompany(): ?int
    {
        return $this->company;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function getUserIdentifier(): string
    {
        return $this->id;
    }
}
