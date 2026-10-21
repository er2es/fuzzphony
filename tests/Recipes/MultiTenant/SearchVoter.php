<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Recipes\MultiTenant;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

// region: voter
/**
 * fuzzphony.authorization asks isGranted('FUZZPHONY_SEARCH', <index name>) before every search made as an actor.
 * This voter grants it to every signed-in user, for every index. A voter that supports only some indexes abstains on
 * the others, and an abstention is a refusal: with the Live Component or the API Platform filter, every index they
 * search needs the attribute granted.
 *
 * @extends Voter<string, string>
 */
final class SearchVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === 'FUZZPHONY_SEARCH' && is_string($subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return in_array('ROLE_USER', $token->getRoleNames(), true);
    }
}
// endregion
