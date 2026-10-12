<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * fuzzphony.authorization asks isGranted('FUZZPHONY_SEARCH', 'intranet') before every search made as an actor: this voter
 * grants it to ROLE_USER. It abstains on every other attribute and index, so a role such as the ROLE_HR of a field
 * (`granted: ROLE_HR`) is decided by Symfony's own role voter.
 *
 * @extends Voter<string, string>
 */
final class SearchVoter extends Voter
{
    public const string ATTRIBUTE = 'FUZZPHONY_SEARCH';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ATTRIBUTE && $subject === 'intranet';
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return in_array('ROLE_USER', $token->getRoleNames(), true);
    }
}
