<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Post\Post;
use App\Entity\User\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class PostVoter extends Voter
{
    // Lowercase keeps the generic permission voter from granting object access.
    public const VIEW = 'post.view';

    public function __construct(private readonly AccessDecisionManagerInterface $decisionManager)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::VIEW && $subject instanceof Post;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || $subject->getDeletedAt() !== null) {
            return false;
        }

        return $subject->isActive()
            || ($user->getId() !== null && $subject->getAuthor()?->getId() === $user->getId())
            || $this->decisionManager->decide($token, ['ROLE_ADMIN']);
    }
}
