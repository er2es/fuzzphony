<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Security;

use Fuzzphony\Bundle\Security\AuthorizationGuard;
use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\AccessDenied;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Tests\Fixtures\FakeAuthorizationChecker;
use PHPUnit\Framework\TestCase;

final class AuthorizationGuardTest extends TestCase
{
    public function testAnIndexThatIsNotGrantedIsDenied(): void
    {
        $guard = new AuthorizationGuard($this->checker([]));

        $this->expectException(AccessDenied::class);
        $this->expectExceptionMessage('"products"');

        $guard->check($this->index(), new Actor());
    }

    public function testAGrantedIndexWithoutFieldRolesIsAll(): void
    {
        $guard = new AuthorizationGuard($this->checker(['FUZZPHONY_SEARCH' => ['products' => true]]));

        self::assertTrue($guard->check($this->index(), new Actor())->isAll());
    }

    public function testAViewerWithoutTheFieldRoleDoesNotSeeTheField(): void
    {
        $checker = $this->checker(['FUZZPHONY_SEARCH' => ['products' => true]]);
        $guard = new AuthorizationGuard($checker);

        $access = $guard->check($this->index(secret: true), new Actor());

        self::assertFalse($access->isAll());
        self::assertSame(['name'], $access->fields);
        self::assertContains(['ROLE_HR', 'products'], $checker->asked, 'the subject of the field check is the index name');
    }

    public function testAViewerWithTheFieldRoleSeesEverything(): void
    {
        $guard = new AuthorizationGuard($this->checker(['FUZZPHONY_SEARCH' => ['products' => true], 'ROLE_HR' => ['products' => true]]));

        self::assertTrue($guard->check($this->index(secret: true), new Actor())->isAll());
    }

    public function testNoTokenFailsClosed(): void
    {
        $guard = new AuthorizationGuard($this->checker([], noToken: true));

        $this->expectException(AccessDenied::class);

        $guard->check($this->index(), new Actor());
    }

    public function testNoTokenOnAFieldCheckFailsClosedToo(): void
    {
        // the index attribute is answered, the field role throws: the whole search is denied, not narrowed
        $guard = new AuthorizationGuard($this->checker(['FUZZPHONY_SEARCH' => ['products' => true]], noTokenFor: 'ROLE_HR'));

        $this->expectException(AccessDenied::class);

        $guard->check($this->index(secret: true), new Actor());
    }

    public function testTheIndexAttributeIsConfigurable(): void
    {
        $checker = $this->checker(['ROLE_USER' => ['products' => true]]);
        $guard = new AuthorizationGuard($checker, 'ROLE_USER');

        self::assertTrue($guard->check($this->index(), new Actor())->isAll());
        self::assertSame([['ROLE_USER', 'products']], $checker->asked);
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
