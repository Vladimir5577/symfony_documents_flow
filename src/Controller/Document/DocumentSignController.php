<?php

namespace App\Controller\Document;

use App\Entity\Document\Document;
use App\Entity\User\User;
use App\Repository\Document\DocumentRepository;
use setasign\Fpdi\Tcpdf\Fpdi;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Штамп исполнителя на PDF документа (легаси-портал).
 *
 * Аудит 2026-09-05 (BE-07 / SEC-07): раньше /executors_signature принимал любой
 * метод, любое тело и ставил штамп на PDF любого документа любым сотрудником по
 * сессии. Теперь: только POST + JSON, id/page/x/y валидируются, подписывать
 * может получатель документа (или ROLE_ADMIN), файл пишется атомарно под
 * блокировкой — два одновременных подписания больше не портят PDF.
 *
 * Демо-маршруты (/document/sign, /sign_document, /convert_docx_to_pdf_document,
 * /edit_pdf, /create_pdf_table_executors, /convert_img_to_pdf) удалены: они
 * запускали LibreOffice по GET и писали в веб-корень public/files, а ни один
 * шаблон на них не ссылался.
 */
final class DocumentSignController extends AbstractController
{
    private const STAMP_WIDTH_MM = 60;
    private const STAMP_HEIGHT_MM = 18;

    #[Route('/executors_signature', name: 'app_executors_signature', methods: ['POST'])]
    public function executorsSignature(
        Request $request,
        DocumentRepository $documentRepository,
        #[Autowire('%private_upload_dir_documents_updated%')] string $updatedDir,
    ): JsonResponse {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            throw $this->createAccessDeniedException('Необходима авторизация.');
        }

        // Только application/json: кросс-сайтовая форма с enctype=text/plain
        // сюда не пройдёт без CORS-preflight.
        if (!str_starts_with((string) $request->headers->get('Content-Type', ''), 'application/json')) {
            return $this->json(['error' => 'Ожидается application/json'], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Некорректный JSON'], Response::HTTP_BAD_REQUEST);
        }

        $documentId = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
        $stampPage = filter_var($data['page'] ?? null, FILTER_VALIDATE_INT);
        $x = filter_var($data['x'] ?? null, FILTER_VALIDATE_FLOAT);
        $y = filter_var($data['y'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($documentId === false || $documentId <= 0 || $stampPage === false || $stampPage < 1 || $x === false || $y === false) {
            return $this->json(['error' => 'Некорректные параметры подписи'], Response::HTTP_BAD_REQUEST);
        }

        $document = $documentRepository->findOneWithRelations($documentId);
        if (!$document instanceof Document) {
            return $this->json(['error' => 'Документ не найден'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->canSign($document, $currentUser)) {
            return $this->json(['error' => 'Подписывать документ может только его получатель'], Response::HTTP_FORBIDDEN);
        }

        // Guard и чтение — один и тот же файл (раньше проверялся original, а читался updated).
        $updatedFile = $document->getUpdatedFile();
        if ($updatedFile === null || $updatedFile === '') {
            return $this->json(['error' => 'У документа нет PDF для подписания'], Response::HTTP_NOT_FOUND);
        }
        $sourcePdf = $updatedDir . '/' . basename($updatedFile);
        if (!is_file($sourcePdf)) {
            return $this->json(['error' => 'Файл документа не найден'], Response::HTTP_NOT_FOUND);
        }

        $userName = trim(implode(' ', array_filter([
            $currentUser->getLastname(),
            $currentUser->getFirstname(),
            $currentUser->getPatronymic(),
        ])));
        $signDate = (new \DateTime())->format('d.m.Y');

        // координаты с фронта: x max = 150, y max = 267 (см. sign_document.html.twig)
        $stampX = $x / 4;
        $stampY = (840 - $y) / 3.15;

        // Блокировка на файл: Fpdi читает и пишет один путь, параллельное подписание
        // без неё могло испортить PDF.
        $lockPath = $sourcePdf . '.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock !== false) {
                fclose($lock);
            }

            return $this->json(['error' => 'Документ сейчас подписывает другой пользователь, повторите позже'], Response::HTTP_CONFLICT);
        }

        try {
            $pdf = new Fpdi();
            $pageCount = $pdf->setSourceFile($sourcePdf);
            if ($stampPage > $pageCount) {
                return $this->json(['error' => sprintf('В документе %d стр.', $pageCount)], Response::HTTP_BAD_REQUEST);
            }

            for ($i = 1; $i <= $pageCount; $i++) {
                $tpl = $pdf->importPage($i);
                $size = $pdf->getTemplateSize($tpl);

                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($tpl);

                if ($i !== $stampPage) {
                    continue;
                }

                $pdf->SetDrawColor(0, 0, 255);
                $pdf->SetTextColor(0, 0, 255);
                $pdf->SetLineWidth(1);

                // Штамп не должен вылезать за страницу: x ∈ [0, W−w], y ∈ [0, H−h]
                $pageW = (float) $size['width'];
                $pageH = (float) $size['height'];
                $sx = max(0, min($stampX, $pageW - self::STAMP_WIDTH_MM));
                $sy = max(0, min($stampY, $pageH - self::STAMP_HEIGHT_MM));

                $pdf->Rect($sx, $sy, self::STAMP_WIDTH_MM, self::STAMP_HEIGHT_MM);
                $pdf->SetFont('dejavusans', '', 10);
                $pdf->SetXY($sx + 3, $sy + 2);
                $pdf->MultiCell(self::STAMP_WIDTH_MM - 6, 5, "Подписано\n{$userName}\n{$signDate}", 0, 'C');
            }

            // Атомарная запись: во временный файл рядом + rename, чтобы прерванная
            // генерация не оставила документ битым.
            $tmpPath = $sourcePdf . '.tmp-' . bin2hex(random_bytes(4));
            $pdf->Output($tmpPath, 'F');
            if (!@rename($tmpPath, $sourcePdf)) {
                @unlink($tmpPath);

                return $this->json(['error' => 'Не удалось сохранить подписанный файл'], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        } finally {
            // Lock-файл остаётся на диске намеренно: unlink после unlock открывал
            // окно, когда второй писатель захватывал старый inode, а третий
            // создавал новый файл и тоже получал замок — два процесса писали
            // один PDF. Пустой .lock рядом с документом ничего не стоит.
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $this->json(['ok' => true]);
    }

    #[Route('/sign_and_save_document/{id}', name: 'app_sign_and_save_document', requirements: ['id' => '\d+'])]
    public function signAndSaveDocument(
        int $id,
        DocumentRepository $documentRepository,
    ): Response {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            throw $this->createAccessDeniedException('Необходима авторизация.');
        }

        $document = $documentRepository->findOneWithRelations($id);
        if (!$document?->getOriginalFile()) {
            throw $this->createNotFoundException('У документа нет файла для подписания.');
        }
        if (!$this->canSign($document, $currentUser)) {
            throw $this->createAccessDeniedException('Подписывать документ может только его получатель.');
        }

        // Показываем тот файл, который будет подписываться: updated (с таблицей исполнителей) или original
        $fileType = $document->getUpdatedFile() ? 'updated' : 'original';
        $fileUrl = $this->generateUrl(
            'app_document_download_file',
            [
                'id' => $document->getId(),
                'type' => $fileType,
                'inline' => 1,
            ],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        return $this->render('document/sign_document.html.twig', [
            'active_tab' => 'incoming_documents',
            'file_url' => $fileUrl,
            'id' => $document->getId(),
        ]);
    }

    /** Подписывает получатель документа (исполнитель/адресат) или администратор. */
    private function canSign(Document $document, User $user): bool
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return true;
        }
        // Черновик подписывать нечего: получатель штампует только опубликованный документ.
        if (!$document->isPublished()) {
            return false;
        }
        foreach ($document->getUserRecipients() as $recipient) {
            if ($recipient->getUser()?->getId() === $user->getId()) {
                return true;
            }
        }

        return false;
    }
}
