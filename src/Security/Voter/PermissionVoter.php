<?php

namespace App\Security\Voter;

use App\Entity\User\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class PermissionVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        if (str_starts_with($attribute, 'ROLE_')) {
            return false;
        }

        return (bool) preg_match('/^[A-Z0-9_]{3,64}$/', $attribute);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return $user->hasPermission($attribute);
    }
}






//
//namespace App\Security\Voter;
//
//use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
//use Symfony\Component\Security\Core\Authorization\Voter\Voter;
//use Symfony\Component\Security\Core\User\UserInterface;
//
//final class PermissionVoter extends Voter
//{
//    public const EDIT = 'POST_EDIT';
//    public const VIEW = 'POST_VIEW';
//
//    protected function supports(string $attribute, mixed $subject): bool
//    {
//        // replace with your own logic
//        // https://symfony.com/doc/current/security/voters.html
//        return in_array($attribute, [self::EDIT, self::VIEW])
//            && $subject instanceof \App\Entity\Permission;
//    }
//
//    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
//    {
//        $user = $token->getUser();
//
//        // if the user is anonymous, do not grant access
//        if (!$user instanceof UserInterface) {
//            return false;
//        }
//
//        // ... (check conditions and return true to grant permission) ...
//        switch ($attribute) {
//            case self::EDIT:
//                // logic to determine if the user can EDIT
//                // return true or false
//                break;
//
//            case self::VIEW:
//                // logic to determine if the user can VIEW
//                // return true or false
//                break;
//        }
//
//        return false;
//    }
//}
