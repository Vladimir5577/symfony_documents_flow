<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Document\UserCertificate;
use App\Entity\User\User;
use App\Enum\Document\CertificateStatus;
use App\Repository\Document\UserCertificateRepository;
use App\Repository\User\UserRepository;
use App\Service\Document\Signature\CertificateAuthorityService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Админка внутреннего УЦ: список сертификатов, выпуск, отзыв, перевыпуск.
 *
 * Файл .p12 существует только в ответе на выпуск и нигде не сохраняется —
 * повторное скачивание невозможно by design.
 * Пароль контейнера .p12 в логи не пишется никогда.
 */
#[Route('/admin/certificates')]
#[IsGranted('ROLE_ADMIN')]
final class CertificateAdminController extends AbstractController
{
    private const P12_PASSWORD_MIN_LENGTH = 8;

    public function __construct(
        private readonly CertificateAuthorityService $certificateAuthority,
        private readonly UserCertificateRepository $certificateRepository,
        private readonly LoggerInterface $caLogger,
    ) {
    }

    #[Route(path: '', name: 'app_admin_certificates', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $statusFilter = CertificateStatus::tryFrom((string) $request->query->get('status', ''));

        $certificates = $this->certificateRepository->findBy(
            $statusFilter !== null ? ['status' => $statusFilter] : [],
            ['createdAt' => 'DESC'],
        );

        $now = new \DateTimeImmutable();

        return $this->render('admin/certificates/index.html.twig', [
            'active_tab' => 'admin_certificates',
            'certificates' => $certificates,
            'status_filter' => $statusFilter?->value,
            'now' => $now,
            'expiring_threshold' => $now->modify('+30 days'),
        ]);
    }

    #[Route(path: '/issue', name: 'app_admin_certificates_issue', methods: ['GET', 'POST'])]
    public function issue(Request $request, UserRepository $userRepository): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('certificate_issue', (string) $request->request->get('_token', ''))) {
                $this->addFlash('error', 'Недействительный CSRF-токен. Повторите попытку.');

                return $this->redirectToRoute('app_admin_certificates_issue');
            }

            $user = $userRepository->findActive($request->request->getInt('user_id'));
            $p12Password = (string) $request->request->get('p12_password', '');

            if ($user === null) {
                $this->addFlash('error', 'Пользователь не найден.');
            } elseif (mb_strlen($p12Password) < self::P12_PASSWORD_MIN_LENGTH) {
                $this->addFlash('error', sprintf('Пароль контейнера .p12 должен быть не короче %d символов.', self::P12_PASSWORD_MIN_LENGTH));
            } else {
                return $this->issueAndDownload($user, $p12Password);
            }

            return $this->redirectToRoute('app_admin_certificates_issue');
        }

        $users = $userRepository->findAllActive();
        usort($users, static fn (User $a, User $b): int => [$a->getLastname(), $a->getFirstname()] <=> [$b->getLastname(), $b->getFirstname()]);

        return $this->render('admin/certificates/issue.html.twig', [
            'active_tab' => 'admin_certificates',
            'users' => $users,
        ]);
    }

    #[Route(path: '/{id}/revoke', name: 'app_admin_certificates_revoke', methods: ['POST'])]
    public function revoke(UserCertificate $certificate, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('certificate_revoke_' . $certificate->getId(), (string) $request->request->get('_token', ''))) {
            $this->addFlash('error', 'Недействительный CSRF-токен. Повторите попытку.');

            return $this->redirectToRoute('app_admin_certificates');
        }

        $reason = trim((string) $request->request->get('reason', ''));
        if ($reason === '') {
            $this->addFlash('error', 'Укажите причину отзыва сертификата.');

            return $this->redirectToRoute('app_admin_certificates');
        }

        if ($certificate->getStatus() !== CertificateStatus::ACTIVE) {
            $this->addFlash('error', 'Сертификат уже отозван.');

            return $this->redirectToRoute('app_admin_certificates');
        }

        /** @var User $admin */
        $admin = $this->getUser();
        $this->certificateAuthority->revoke($certificate, $reason, $admin);

        $this->caLogger->info('Сертификат отозван', [
            'serial_number' => $certificate->getSerialNumber(),
            'user' => $certificate->getUser()?->getLogin(),
            'revoked_by' => $admin->getLogin(),
            'reason' => $reason,
        ]);

        $this->addFlash('success', sprintf('Сертификат %s отозван.', $certificate->getSerialNumber()));

        return $this->redirectToRoute('app_admin_certificates');
    }

    /**
     * Перевыпуск = отзыв (причина «reissued») + выпуск нового сертификата.
     * Ответ — скачивание нового .p12 (один раз).
     */
    #[Route(path: '/{id}/reissue', name: 'app_admin_certificates_reissue', methods: ['POST'])]
    public function reissue(UserCertificate $certificate, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('certificate_reissue_' . $certificate->getId(), (string) $request->request->get('_token', ''))) {
            $this->addFlash('error', 'Недействительный CSRF-токен. Повторите попытку.');

            return $this->redirectToRoute('app_admin_certificates');
        }

        $p12Password = (string) $request->request->get('p12_password', '');
        if (mb_strlen($p12Password) < self::P12_PASSWORD_MIN_LENGTH) {
            $this->addFlash('error', sprintf('Пароль контейнера .p12 должен быть не короче %d символов.', self::P12_PASSWORD_MIN_LENGTH));

            return $this->redirectToRoute('app_admin_certificates');
        }

        if ($certificate->getStatus() !== CertificateStatus::ACTIVE) {
            $this->addFlash('error', 'Перевыпустить можно только активный сертификат.');

            return $this->redirectToRoute('app_admin_certificates');
        }

        $user = $certificate->getUser();
        if ($user === null) {
            $this->addFlash('error', 'У сертификата не указан владелец.');

            return $this->redirectToRoute('app_admin_certificates');
        }

        /** @var User $admin */
        $admin = $this->getUser();
        $this->certificateAuthority->revoke($certificate, 'reissued', $admin);

        $this->caLogger->info('Сертификат отозван', [
            'serial_number' => $certificate->getSerialNumber(),
            'user' => $user->getLogin(),
            'revoked_by' => $admin->getLogin(),
            'reason' => 'reissued',
        ]);

        return $this->issueAndDownload($user, $p12Password);
    }

    private function issueAndDownload(User $user, string $p12Password): Response
    {
        /** @var User $admin */
        $admin = $this->getUser();
        $result = $this->certificateAuthority->issueCertificate($user, $p12Password, $admin);

        // Пароль .p12 в лог НЕ пишем.
        $this->caLogger->info('Выпущен сертификат', [
            'serial_number' => $result->certificate->getSerialNumber(),
            'user' => $user->getLogin(),
            'user_id' => $user->getId(),
            'issued_by' => $admin->getLogin(),
            'valid_to' => $result->certificate->getValidTo()?->format(\DateTimeInterface::ATOM),
        ]);

        $response = new Response($result->p12Binary);
        $response->headers->set('Content-Type', 'application/x-pkcs12');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            sprintf('certificate_%s.p12', $user->getLogin()),
            'certificate.p12',
        ));

        return $response;
    }
}
