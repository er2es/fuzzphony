<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Recipes\MultiTenant;

/** A customer of the SaaS: the tenant. */
final class Account
{
    public function __construct(public readonly int $id) {}
}
