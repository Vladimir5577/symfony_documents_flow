<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User\User;
use App\Service\User\UserAvatarUrlGenerator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class UserAvatarExtension extends AbstractExtension
{
    public function __construct(
        private readonly UserAvatarUrlGenerator $avatarUrlGenerator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('user_avatar_url', [$this, 'getUserAvatarUrl']),
        ];
    }

    public function getUserAvatarUrl(?User $user, string $filter = UserAvatarUrlGenerator::FILTER_MEDIUM): ?string
    {
        if (!$user instanceof User) {
            return null;
        }

        return $this->avatarUrlGenerator->getAvatarUrl($user, $filter);
    }
}
