<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Security;

use Fuzzphony\Bundle\Security\PropertyPathActorResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;

final class PropertyPathActorResolverMutationsTest extends TestCase
{
    public function testAnIntegerUserValueBecomesTheStringUser(): void
    {
        $actor = $this->resolver(new MeasuredUser(42, 1.5), null, 'id')->resolve();

        self::assertSame('42', $actor->user, 'the user of an Actor is a string, a number read from the user object is cast');
    }

    public function testANumericTenantKeepsItsIntegerType(): void
    {
        $actor = $this->resolver(new MeasuredUser(42, 1.5), 'id', null)->resolve();

        self::assertSame(42, $actor->tenant);
    }

    public function testAFloatValueIsReadAsItsStringForm(): void
    {
        $actor = $this->resolver(new MeasuredUser(42, 1.5), 'score', 'score')->resolve();

        self::assertSame('1.5', $actor->tenant);
        self::assertSame('1.5', $actor->user);
    }

    private function resolver(UserInterface $user, ?string $tenantPath, ?string $userPath): PropertyPathActorResolver
    {
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken($user, 'main'));

        return new PropertyPathActorResolver($tokens, PropertyAccess::createPropertyAccessor(), $tenantPath, $userPath);
    }
}

/** A user with a number and a float property (eraseCredentials() is declared by Symfony 7.4 and gone in 8.0). */
final class MeasuredUser implements UserInterface
{
    public function __construct(public readonly int $id, public readonly float $score) {}

    public function getRoles(): array
    {
        return [];
    }

    public function eraseCredentials(): void {}

    public function getUserIdentifier(): string
    {
        return 'measured';
    }
}
