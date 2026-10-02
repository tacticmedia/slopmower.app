<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Draft;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Drafts are private to their user.
 *
 * @extends Voter<string, Draft>
 */
final class DraftVoter extends Voter
{
    public const string OWN = 'DRAFT_OWN';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::OWN === $attribute && $subject instanceof Draft;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof User && $subject->getUser()->getId()->equals($user->getId());
    }
}
