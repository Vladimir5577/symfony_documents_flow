<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Organization\AbstractOrganization;
use App\Entity\Organization\Organization;
use App\Enum\Organization\OrganizationType;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class OrganizationActionsVisibilityTest extends KernelTestCase
{
    public static function roles(): iterable
    {
        foreach (['ROLE_USER', 'ROLE_HR', 'ROLE_INVENTORY_MANAGER', 'ROLE_MANAGER', 'ROLE_ADMIN'] as $role) {
            yield $role => [$role, $role === 'ROLE_ADMIN'];
        }
    }

    #[DataProvider('roles')]
    public function testOnlyAdministratorsSeeEditAndDelete(string $role, bool $visible): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $user = new InMemoryUser('organization-actions-test', null, [$role]);
        $container->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $request = Request::create('/view_organization/2');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get('request_stack')->push($request);
        $organization = (new Organization())->setName('Test organization');
        (new \ReflectionProperty(AbstractOrganization::class, 'id'))->setValue($organization, 2);

        $html = $container->get('twig')->load('organization/view_organization.html.twig')->renderBlock('main', [
            'organization' => $organization,
            'organization_type' => OrganizationType::ORGANIZATION,
            'type_organization' => OrganizationType::ORGANIZATION->getLabel(),
            'back_organization' => null,
            'users' => [],
        ]);

        foreach (['href="/edit_organization/2"', 'action="/delete_organization/2"', 'id="delete-organization-btn"'] as $action) {
            self::assertSame($visible, str_contains($html, $action), $role . ': ' . $action);
        }
        self::assertStringContainsString('Test organization', $html);
    }
}
