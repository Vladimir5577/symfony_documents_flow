<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Documents;

use App\Entity\Document\DocumentFileSignature;
use App\Entity\Document\File;
use App\Entity\User\User;
use App\Repository\Document\DocumentFileSignatureRepository;
use App\Service\Document\DocumentFileStorage;
use Doctrine\ORM\EntityManagerInterface;
use setasign\Fpdi\Tcpdf\Fpdi;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Простая подпись PDF-вложения штампом. В базе — только место штампа,
 * оригинал в MinIO не меняется, PDF со штампами собирается при скачивании.
 */
final class DocumentFileSignatureService
{
    /**
     * Ширина штампа — доля короткой стороны страницы (на A4 ≈ 76 мм), высота — от ширины.
     * От страницы, а не в мм: на скане с огромной страницей штамп в мм теряется.
     */
    private const STAMP_WIDTH_SHARE = 0.36;
    private const STAMP_RATIO = 0.36;

    /** Тёмно-синий, как у печати. */
    private const STAMP_COLOR = [21, 67, 160];

    /** В контейнере TZ=UTC, а на штампе нужно местное время. */
    private const STAMP_TIMEZONE = 'Europe/Moscow';

    public function __construct(
        private readonly DocumentFileSignatureRepository $signatures,
        private readonly EntityManagerInterface $em,
        private readonly DocumentFileStorage $storage,
        private readonly DocumentApiPresenter $presenter,
    ) {
    }

    public static function isPdf(File $file): bool
    {
        return strtolower(pathinfo((string) $file->getFilePath(), PATHINFO_EXTENSION)) === 'pdf';
    }

    /** Подписать ещё раз = перенести свой штамп. */
    public function sign(File $file, User $user, int $page, float $x, float $y): void
    {
        $signature = $this->signatures->findOneBy(['file' => $file, 'user' => $user])
            ?? new DocumentFileSignature($file, $user);
        $signature->place($page, $x, $y);
        $this->em->persist($signature);
        $this->em->flush();
    }

    public function unsign(File $file, User $user): void
    {
        $signature = $this->signatures->findOneBy(['file' => $file, 'user' => $user]);
        if ($signature !== null) {
            $this->em->remove($signature);
            $this->em->flush();
        }
    }

    /** @return list<array<string, mixed>> */
    public function present(File $file, User $viewer): array
    {
        return array_map(fn (DocumentFileSignature $s): array => [
            'id' => $s->getId(),
            'user' => $this->presenter->presentUserBrief($s->getUser()),
            'page' => $s->getPage(),
            'x' => $s->getX(),
            'y' => $s->getY(),
            'createdAt' => $s->getCreatedAt()->format(\DATE_ATOM),
            'isMine' => $s->getUser()->getId() === $viewer->getId(),
        ], $this->signatures->findForFile($file));
    }

    /**
     * Байты PDF со штампами. null — исходника нет в хранилище.
     *
     * @throws ProcessFailedException qpdf не смог разобрать файл
     */
    public function buildSignedPdf(File $file): ?string
    {
        $fs = new Filesystem();
        $dir = sys_get_temp_dir() . '/signed_pdf_' . bin2hex(random_bytes(8));
        $fs->mkdir($dir);

        try {
            $source = $dir . '/source.pdf';
            if (!$this->storage->saveTo(DocumentFileStorage::attachmentKey($file), $source)) {
                return null;
            }

            // Бесплатный парсер FPDI не читает сжатые таблицы xref (PDF 1.5+),
            // а так сохраняют Word, Acrobat и браузеры. qpdf переписывает их
            // по-старому, содержимое страниц не трогает. Код 3 — предупреждения.
            $plain = $dir . '/plain.pdf';
            $qpdf = new Process(['qpdf', '--decrypt', '--object-streams=disable', $source, $plain]);
            $qpdf->run();
            if (!\in_array($qpdf->getExitCode(), [0, 3], true)) {
                throw new ProcessFailedException($qpdf);
            }

            return $this->stamp($plain, $this->signatures->findForFile($file));
        } finally {
            $fs->remove($dir);
        }
    }

    /** @param list<DocumentFileSignature> $signatures */
    private function stamp(string $path, array $signatures): string
    {
        $byPage = [];
        foreach ($signatures as $signature) {
            $byPage[$signature->getPage()][] = $signature;
        }

        $pdf = new Fpdi();
        // Без этого TCPDF рисует линии колонтитулов и переносит штамп у нижнего края на новую страницу.
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);

        $pageCount = $pdf->setSourceFile($path);
        for ($i = 1; $i <= $pageCount; ++$i) {
            $tpl = $pdf->importPage($i);
            $size = $pdf->getTemplateSize($tpl);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($tpl);

            foreach ($byPage[$i] ?? [] as $signature) {
                $this->drawStamp($pdf, $signature, (float) $size['width'], (float) $size['height']);
            }
        }

        return $pdf->Output('', 'S');
    }

    private function drawStamp(Fpdi $pdf, DocumentFileSignature $signature, float $pageW, float $pageH): void
    {
        $w = min($pageW, $pageH) * self::STAMP_WIDTH_SHARE;
        $h = $w * self::STAMP_RATIO;
        $k = $w / 76; // вся разметка ниже нарисована для ширины 76 мм

        $x = max(0, min($signature->getX() * $pageW - $w / 2, $pageW - $w));
        $y = max(0, min($signature->getY() * $pageH - $h / 2, $pageH - $h));
        $date = $signature->getCreatedAt()
            ->setTimezone(new \DateTimeZone(self::STAMP_TIMEZONE))
            ->format('d.m.Y в H:i');

        [$r, $g, $b] = self::STAMP_COLOR;
        $pdf->SetDrawColor($r, $g, $b);
        $pdf->SetTextColor($r, $g, $b);

        // Двойная рамка, как у печати.
        $pdf->SetLineWidth(0.8 * $k);
        $pdf->RoundedRect($x, $y, $w, $h, 2.5 * $k, '1111', 'D');
        $pdf->SetLineWidth(0.25 * $k);
        $pdf->RoundedRect($x + 1.3 * $k, $y + 1.3 * $k, $w - 2.6 * $k, $h - 2.6 * $k, 1.6 * $k, '1111', 'D');

        $inner = $w - 8 * $k;
        $pdf->SetFont('dejavusans', 'B', 11 * $k);
        $pdf->SetXY($x + 4 * $k, $y + 3 * $k);
        $pdf->Cell($inner, 6 * $k, 'ПОДПИСАНО', 0, 0, 'C');

        $pdf->Line($x + 8 * $k, $y + 10 * $k, $x + $w - 8 * $k, $y + 10 * $k);

        // Длинное ФИО сжимается по ширине (stretch = 1), а не переносится.
        $pdf->SetFont('dejavusans', 'B', 9.5 * $k);
        $pdf->SetXY($x + 4 * $k, $y + 11.5 * $k);
        $pdf->Cell($inner, 6 * $k, $this->presenter->formatUserFullName($signature->getUser()), 0, 0, 'C', false, '', 1);

        $pdf->SetFont('dejavusans', '', 8.5 * $k);
        $pdf->SetXY($x + 4 * $k, $y + 18.5 * $k);
        $pdf->Cell($inner, 5 * $k, $date, 0, 0, 'C');
    }
}
