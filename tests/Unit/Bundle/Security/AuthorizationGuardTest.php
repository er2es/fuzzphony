<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Security;

use Fuzzphony\Bundle\Security\AuthorizationGuard;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\AccessDenied;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Tests\Fixtures\FakeAuthorizationChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class AuthorizationGuardTest extends TestCase
{
    public function testAnIndexThatIsNotGrantedIsDenied(): void
    {
        $guard = $this->guard($this->checker([]));

        $this->expectException(AccessDenied::class);
        $this->expectExceptionMessage('"products"');

        $guard->check($this->index(), new Actor());
    }

    public function testAGrantedIndexWithoutFieldRolesIsAll(): void
    {
        $guard = $this->guard($this->checker(['FUZZPHONY_SEARCH' => ['products' => true]]));

        self::assertTrue($guard->check($this->index(), new Actor())->isAll());
    }

    public function testAViewerWithoutTheFieldRoleDoesNotSeeTheField(): void
    {
        $checker = $this->checker(['FUZZPHONY_SEARCH' => ['products' => true]]);
        $guard = $this->guard($checker);

        $access = $guard->check($this->index(secret: true), new Actor());

        self::assertFalse($access->isAll());
        self::assertSame(['name'], $access->fields);
        self::assertContains(['ROLE_HR', 'products'], $checker->asked, 'the subject of the field check is the index name');
    }

    public function testAViewerWithTheFieldRoleSeesEverything(): void
    {
        $guard = $this->guard($this->checker(['FUZZPHONY_SEARCH' => ['products' => true], 'ROLE_HR' => ['products' => true]]));

        self::assertTrue($guard->check($this->index(secret: true), new Actor())->isAll());
    }

    public function testWithoutATokenInTheStorageTheCheckerIsNotAskedAndTheSearchIsDenied(): void
    {
        $checker = $this->checker(['FUZZPHONY_SEARCH' => ['products' => true]]);
        $guard = new AuthorizationGuard($checker, new TokenStorage());

        try {
            $guard->check($this->index(), new Actor());
            self::fail('A request outside any firewall must be denied.');
        } catch (AccessDenied $e) {
            self::assertStringContainsString('"products"', $e->getMessage());
            self::assertSame([], $checker->asked);
        }
    }

    public function testARealCheckerWithAGrantingVoterButAnEmptyTokenStorageDenies(): void
    {
        // Symfony's AuthorizationChecker never throws without a token: it hands the voters a NullToken, and a voter
        // that grants without looking at the user (or PUBLIC_ACCESS) would let an anonymous request search
        $storage = new TokenStorage();
        $guard = new AuthorizationGuard($this->realChecker($storage), $storage);

        $this->expectException(AccessDenied::class);

        $guard->check($this->index(), new Actor());
    }

    public function testARealCheckerWithAGrantingVoterAndATokenGrantsEverything(): void
    {
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken(new InMemoryUser('ada', null), 'main'));
        $guard = new AuthorizationGuard($this->realChecker($storage), $storage);

        self::assertTrue($guard->check($this->index(secret: true), new Actor())->isAll());
    }

    public function testACheckerThatLosesTheTokenWhileAskingFailsClosed(): void
    {
        $guard = $this->guard($this->checker([], noToken: true));

        $this->expectException(AccessDenied::class);

        $guard->check($this->index(), new Actor());
    }

    public function testNoTokenOnAFieldCheckFailsClosedToo(): void
    {
        // the index attribute is answered, the field role throws: the whole search is denied, not narrowed
        $guard = $this->guard($this->checker(['FUZZPHONY_SEARCH' => ['products' => true]], noTokenFor: 'ROLE_HR'));

        $this->expectException(AccessDenied::class);

        $guard->check($this->index(secret: true), new Actor());
    }

    public function testTheIndexAttributeIsConfigurable(): void
    {
        $checker = $this->checker(['ROLE_USER' => ['products' => true]]);
        $guard = $this->guard($checker, 'ROLE_USER');

        self::assertTrue($guard->check($this->index(), new Actor())->isAll());
        self::assertSame([['ROLE_USER', 'products']], $checker->asked);
    }

    /** A guard with a token in the storage (the fake checker decides). */
    private function guard(FakeAuthorizationChecker $checker, string $indexAttribute = 'FUZZPHONY_SEARCH'): AuthorizationGuard
    {
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken(new InMemoryUser('ada', null), 'main'));

        return new AuthorizationGuard($checker, $storage, $indexAttribute);
    }

    /** Symfony's own checker with a voter that grants everything without looking at the token. */
    private function realChecker(TokenStorage $storage): AuthorizationChecker
    {
        $voter = new class implements VoterInterface {
            public function vote(TokenInterface $token, mixed $subject, array $attributes, ?Vote $vote = null): int
            {
                return self::ACCESS_GRANTED;
            }
        };

        return new AuthorizationChecker($storage, new AccessDecisionManager([$voter]));
    }

    private function index(bool $secret = false): IndexDefinition
    {
        $builder = IndexDefinition::builder('products')
            ->fromQuery('SELECT id, name, secret FROM fz_product')
            ->watch('fz_product')
            ->field('name', 'A');
        if ($secret) {
            $builder->field('secret', 'B', granted: 'ROLE_HR');
        }

        return $builder->build();
    }

    /** @param array<string, array<string, bool>> $grants attribute => subject => granted */
    private function checker(array $grants, bool $noToken = false, ?string $noTokenFor = null): FakeAuthorizationChecker
    {
        return new FakeAuthorizationChecker($grants, $noToken, $noTokenFor);
    }
}
