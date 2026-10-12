<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Security;

use Fuzzphony\Bundle\Security\PropertyPathActorResolver;
use Fuzzphony\Core\Exception\InvalidArgument;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

final class PropertyPathActorResolverTest extends TestCase
{
    public function testTheTenantAndTheUserAreReadFromTheSecurityUser(): void
    {
        $actor = $this->resolver(new TenantUser('ada', new Account(7)), 'account.id', 'userIdentifier')->resolve();

        self::assertSame(7, $actor->tenant);
        self::assertSame('ada', $actor->user);
    }

    public function testNoTokenIsTheAnonymousActor(): void
    {
        self::assertTrue($this->resolver(null, 'account.id', 'userIdentifier')->resolve()->isAnonymous());
    }

    public function testAPathThatIsNotConfiguredStaysNull(): void
    {
        $actor = $this->resolver(new TenantUser('ada', new Account(7)), null, 'userIdentifier')->resolve();

        self::assertNull($actor->tenant);
        self::assertSame('ada', $actor->user);
    }

    public function testAnUnreadablePathOrANullValueIsNullNotAnError(): void
    {
        // a user class without the property (a different firewall's user) must not break the search
        $actor = $this->resolver(new InMemoryUser('bob', null), 'account.id', null)->resolve();

        self::assertNull($actor->tenant);
    }

    public function testANonScalarValueIsRefusedNotCast(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('fuzzphony.actor.tenant');

        $this->resolver(new TenantUser('ada', new Account(7)), 'account', null)->resolve();
    }

    private function resolver(?UserInterface $user, ?string $tenantPath, ?string $userPath): PropertyPathActorResolver
    {
        $tokens = new TokenStorage();
        if ($user !== null) {
            $tokens->setToken(new UsernamePasswordToken($user, 'main'));
        }

        return new PropertyPathActorResolver($tokens, PropertyAccess::createPropertyAccessor(), $tenantPath, $userPath);
    }
}

final class Account
{
    public function __construct(public readonly int $id) {}
}

/**
 * eraseCredentials() is declared by Symfony 7.4's UserInterface and gone in 8.0: an extra public method
 * satisfies both, so the one class works on every supported version.
 */
final class TenantUser implements UserInterface
{
    /** @param non-empty-string $name */
    public function __construct(private readonly string $name, public readonly Account $account) {}

    public function getRoles(): array
    {
        return [];
    }

    public function eraseCredentials(): void {}

    public function getUserIdentifier(): string
    {
        return $this->name;
    }
}
