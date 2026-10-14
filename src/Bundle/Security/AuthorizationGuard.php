<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\Security;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\AccessDenied;
use Fuzzphony\Core\Search\Access;
use Fuzzphony\Core\Search\Actor;
use Fuzzphony\Core\Search\SearchGuard;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationCredentialsNotFoundException;

/**
 * @internal Asks Symfony's authorization checker: isGranted(indexAttribute, indexName) for the index, and
 *           isGranted(field.granted, indexName) for every field that names a role. No token fails closed: the guard
 *           checks the token storage itself, because the checker lets the voters decide for a NullToken.
 */
final readonly class AuthorizationGuard implements SearchGuard
{
    public function __construct(
        private AuthorizationCheckerInterface $checker,
        private TokenStorageInterface $tokens,
        private string $indexAttribute = 'FUZZPHONY_SEARCH',
    ) {}

    // $charge is unused here: the SearchGuard interface has it for the guards that spend a rate-limit token (RateLimitGuard)
    public function check(IndexDefinition $index, Actor $actor, bool $charge = true): Access
    {
        if ($this->tokens->getToken() === null) {
            throw AccessDenied::forIndex($index->name); // outside any firewall: the voters would see a NullToken
        }
        try {
            if (!$this->checker->isGranted($this->indexAttribute, $index->name)) {
                throw AccessDenied::forIndex($index->name);
            }
            $allowed = [];
            foreach ($index->fields as $field) {
                if ($field->granted === null || $this->checker->isGranted($field->granted, $index->name)) {
                    $allowed[] = $field->name;
                }
            }
        } catch (AuthenticationCredentialsNotFoundException) {
            throw AccessDenied::forIndex($index->name); // the token went away while asking: fail closed
        }

        return count($allowed) === count($index->fields) ? Access::all() : Access::only($allowed);
    }
}
