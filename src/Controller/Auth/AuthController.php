<?php

namespace App\Controller\Auth;

use App\Entity\User;
use App\Utils\LoginGenerator;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class AuthController extends AbstractController
{

    #[Route(path: '/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository,
        LoginGenerator $loginGenerator
    ): Response
    {
        $formData = [];
        if ($request->isMethod('POST')) {
            $formData = $request->request->all();
            $request->getSession()->set('register_form_data', $formData);

            if (!$this->isCsrfTokenValid('register', $formData['_csrf_token'] ?? '')) {
                $request->getSession()->set('register_error', 'Неверный CSRF токен.');
                return $this->redirectToRoute('app_register');
            }

            $lastname = trim((string) ($formData['fname-column'] ?? ''));
            $firstname = trim((string) ($formData['lname-column'] ?? ''));
            $plainPassword = (string) ($formData['plain_password'] ?? '');
            $confirmPassword = (string) ($formData['confirm_password'] ?? '');

            if ($lastname === '' || $firstname === '') {
                $request->getSession()->set('register_error', 'Имя и фамилия обязательны.');
                return $this->redirectToRoute('app_register');
            }

            if ($plainPassword !== $confirmPassword) {
                $request->getSession()->set('register_error', 'Пароли не совпадают.');
                return $this->redirectToRoute('app_register');
            }

            $login = $loginGenerator->generateLoginBase($lastname, $firstname);
            if ($login === '') {
                $request->getSession()->set('register_error', 'Не удалось сформировать логин.');
                return $this->redirectToRoute('app_register');
            }

            $user = new User();
            $user->setLogin($login);
            $user->setLastname($lastname ?: null);
            $user->setFirstname($firstname ?: null);
            $user->setPatronymic(trim((string) ($formData['city-column'] ?? '')) ?: null);
            $user->setPhone(trim((string) ($formData['phone-column'] ?? '')) ?: null);
            $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));

            $entityManager->persist($user);
            $entityManager->flush();

            $request->getSession()->remove('register_form_data');
            $request->getSession()->remove('register_error');
            $this->addFlash('success', 'Регистрация успешна.');

            return $this->redirectToRoute('app_register');
        } else {
            $formData = $request->getSession()->get('register_form_data', []);
            $request->getSession()->remove('register_form_data');
        }

        $error = $request->getSession()->get('register_error');
        $request->getSession()->remove('register_error');

        return $this->render('auth/register.html.twig', [
            'error' => $error,
            'form_data' => $formData,
        ]);
    }

    #[Route(path: '/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dash_board');
        }

        // get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();

        // last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('auth/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }

    #[Route(path: '/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}
