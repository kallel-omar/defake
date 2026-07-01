<?php

namespace App\Security\Voter;

use App\Entity\PostCheck;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class PostCheckVoter extends Voter
{
    public const VIEW = 'POST_CHECK_VIEW';
    public const DELETE = 'POST_CHECK_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::DELETE], true)
            && $subject instanceof PostCheck;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        if (!$subject instanceof PostCheck) {
            return false;
        }

        if (in_array('ROLE_ADMIN', $token->getRoleNames(), true)) {
            return true;
        }

        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        $owner = $subject->getUser();

        return $owner instanceof User && $owner->getId() === $user->getId();
    }
}
