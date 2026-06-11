<?php

declare(strict_types=1);

namespace App\Service\ContractApplication;

use App\DTO\ContractApplication\ContractApplicationDto;
use App\DTO\ContractApplication\ContractApplicationFileDto;
use App\Service\ApiExternal\ContractApplication\ContractApplicationApiService;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\Tcpdf\Fpdi;

final class ContractApplicationPdfService
{
    private const FONT = 'dejavusans';

    private const IMAGE_EXTENSIONS = ['jpeg', 'jpg', 'png', 'gif', 'webp', 'bmp'];

    public function __construct(
        private readonly ContractApplicationApiService $contractApplicationApiService,
    ) {
    }

    public function generate(ContractApplicationDto $application): string
    {
        $pdf = new Fpdi('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('document_flow');
        $pdf->SetTitle('Заявка ' . $application->publicId);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();

        $this->writeTitle($pdf, 'Заявка ' . $application->publicId);
        $this->writeRows($pdf, [
            'Номер'        => $application->publicId,
            'Статус'       => $application->getStatusLabel(),
            'Дата подачи'  => $application->createdAt->format('d.m.Y H:i'),
        ]);

        $this->writeSection($pdf, 'Данные потребителя', [
            'Тип'           => $application->getConsumerTypeLabel(),
            'Наименование'  => $application->consumerName,
            'Организация'   => $application->organization,
            'Телефон'       => $application->primaryPhone,
            'Email'         => $application->primaryEmail,
        ]);

        if ($application->adminComment !== null && $application->adminComment !== '') {
            $this->writeSection($pdf, 'Комментарий администратора', [
                '' => $application->adminComment,
            ]);
        }

        if ($application->requisites) {
            $r = $application->requisites;
            $this->writeSection($pdf, 'Реквизиты', [
                'Полное наименование'  => $r['organizationName'] ?? null,
                'Юридический адрес'    => $r['legalAddress'] ?? null,
                'Фактический адрес'    => $r['actualAddress'] ?? null,
                'ИНН'                  => $r['inn'] ?? null,
                'КПП'                  => $r['kpp'] ?? null,
                'ОГРН'                 => $r['ogrn'] ?? null,
                'Руководитель'         => $this->joinNonEmpty([$r['director'] ?? null, $r['directorPosition'] ?? null], ', '),
                'Представитель'        => $r['representativeName'] ?? null,
                'Телефон организации'  => $r['orgPhone'] ?? null,
                'Email организации'    => $r['orgEmail'] ?? null,
                'Расчётный счёт'       => $r['bankAccount'] ?? null,
                'Банк'                 => $r['bankName'] ?? null,
                'БИК'                  => $r['bankBik'] ?? null,
                'Корр. счёт'           => $r['bankCorr'] ?? null,
                'ФИО'                  => $r['personName'] ?? null,
                'Адрес'                => $r['personAddress'] ?? null,
                'Паспорт серия'        => $r['passportSeries'] ?? null,
                'Паспорт выдан'        => $this->joinNonEmpty([$r['passportIssuedBy'] ?? null, $r['passportIssuedDate'] ?? null], ' '),
            ]);
        }

        if ($application->signer) {
            $s = $application->signer;
            $this->writeSection($pdf, 'Подписант и доставка', [
                'Подписант'          => $this->signerTypeLabel($s['signerType'] ?? null),
                'ФИО подписанта'     => $s['signerName'] ?? null,
                'Должность'          => $s['signerPosition'] ?? null,
                'Телефон подписанта' => $s['signerPhone'] ?? null,
                'Email подписанта'   => $s['signerEmail'] ?? null,
                'Документ'           => $s['signerDocument'] ?? null,
                'Способ доставки'    => $this->deliveryMethodLabel($s['deliveryMethod'] ?? null),
                'ЭДО оператор'       => (($s['edo'] ?? null) === 'yes') ? ($s['edoOperator'] ?? '—') : null,
                'ЭДО ID'             => (($s['edo'] ?? null) === 'yes') ? ($s['edoId'] ?? '—') : null,
            ]);
        }

        if ($application->waste) {
            $w = $application->waste;
            $unit = ($w['calcUnit'] ?? null) === 'm3' ? 'м³' : ($w['calcUnit'] ?? '');
            $amount = isset($w['calcAmount']) && $w['calcAmount'] !== ''
                ? trim($w['calcAmount'] . ($unit ? ' ' . $unit : ''))
                : null;
            $this->writeSection($pdf, 'Сведения об отходах', [
                'Категория объекта' => $this->objectCategoryLabel($w['objectCategory'] ?? null),
                'Вид отходов'       => $w['wasteName'] ?? null,
                'Код ФККО'          => $w['wasteCode'] ?? null,
                'Объём'             => $amount,
                'Паспорт отходов'   => isset($w['wastePassport']) ? ($w['wastePassport'] === 'yes' ? 'Есть' : 'Нет') : null,
            ]);
        }

        if ($application->site) {
            $si = $application->site;
            $this->writeSection($pdf, 'Объект', [
                'Название'                  => $si['siteName'] ?? null,
                'Адрес'                     => $si['siteAddress'] ?? null,
                'Вид деятельности'          => $si['siteActivity'] ?? null,
                'Площадь'                   => isset($si['siteArea']) && $si['siteArea'] !== '' ? $si['siteArea'] . ' м²' : null,
                'Численность сотрудников'   => $si['siteStaffCount'] ?? null,
                'Назначение'                => isset($si['sitePurpose']) ? ($si['sitePurpose'] === 'residential' ? 'Жилое' : 'Нежилое') : null,
                'Право пользования'         => $this->ownershipLabel($si['ownership'] ?? null),
                'Документ на собственность' => $si['ownershipDocDetails'] ?? null,
                'Документ на аренду'        => $si['rentDocDetails'] ?? null,
            ]);
        }

        if ($application->containers) {
            $c = $application->containers;
            $volume = isset($c['containerVolume']) && $c['containerVolume'] !== ''
                ? $c['containerVolume'] . ' м³' . (!empty($c['containerVolumeOther']) ? ' (' . $c['containerVolumeOther'] . ')' : '')
                : null;
            $this->writeSection($pdf, 'Контейнеры и вывоз', [
                'Контейнеры'        => $this->containerOwnershipLabel($c['containerOwnership'] ?? null),
                'Материал'          => $this->containerMaterialLabel($c['containerMaterial'] ?? null),
                'Тип контейнера'    => $c['containerKind'] ?? null,
                'Объём контейнера'  => $volume,
                'Количество'        => isset($c['containerCount']) && $c['containerCount'] !== '' ? $c['containerCount'] . ' шт.' : null,
                'График вывоза'     => $this->containerScheduleLabel($c['containerSchedule'] ?? null),
                'Место накопления'  => $c['accumulationAddress'] ?? null,
            ]);
        }

        if ($application->extra) {
            $e = $application->extra;
            $this->writeSection($pdf, 'Дополнительная информация', [
                'Контактное лицо на объекте' => $e['siteContactName'] ?? null,
                'Телефон контактного лица'   => $e['siteContactPhone'] ?? null,
                'Условия доступа'            => $e['accessConditions'] ?? null,
                'Сообщение'                  => $e['message'] ?? null,
            ]);
        }

        if (\count($application->files) > 0) {
            $rows = [];
            foreach ($application->files as $i => $file) {
                $rows[(string) ($i + 1)] = $file->originalName . ' (' . $file->getFileSizeFormatted() . ')';
            }
            $this->writeSection($pdf, 'Прикреплённые файлы (' . \count($application->files) . ')', $rows);

            foreach ($application->files as $file) {
                $this->appendFile($pdf, $file);
            }
        }

        return (string) $pdf->Output('', 'S');
    }

    private function appendFile(Fpdi $pdf, ContractApplicationFileDto $file): void
    {
        $ext = strtolower(pathinfo($file->originalName, PATHINFO_EXTENSION));

        try {
            $content = $this->contractApplicationApiService->getFileContent($file->id)['content'];
        } catch (\Throwable $e) {
            $this->appendPlaceholderPage(
                $pdf,
                $file,
                'Не удалось загрузить файл для встраивания.',
            );
            return;
        }

        if ($ext === 'pdf') {
            $this->appendPdf($pdf, $file, $content);
            return;
        }

        if (\in_array($ext, self::IMAGE_EXTENSIONS, true)) {
            $this->appendImage($pdf, $file, $content);
            return;
        }

        $this->appendPlaceholderPage(
            $pdf,
            $file,
            'Файл этого типа нельзя встроить в PDF — он приложен к заявке отдельно.',
        );
    }

    private function appendPdf(Fpdi $pdf, ContractApplicationFileDto $file, string $content): void
    {
        try {
            $pageCount = $pdf->setSourceFile(StreamReader::createByString($content));
            for ($i = 1; $i <= $pageCount; $i++) {
                $tpl  = $pdf->importPage($i);
                $size = $pdf->getTemplateSize($tpl);

                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($tpl);
            }
        } catch (\Throwable $e) {
            $this->appendPlaceholderPage(
                $pdf,
                $file,
                'Не удалось встроить PDF-файл (возможно, повреждён или защищён).',
            );
        }
    }

    private function appendImage(Fpdi $pdf, ContractApplicationFileDto $file, string $content): void
    {
        $info = @getimagesizefromstring($content);
        if ($info === false) {
            $this->appendPlaceholderPage($pdf, $file, 'Не удалось обработать изображение.');
            return;
        }

        $pdf->AddPage();
        $this->writeFileCaption($pdf, $file);

        [$imgWidth, $imgHeight] = $info;

        $marginX    = 15;
        $top        = $pdf->GetY() + 2;
        $pageWidth  = $pdf->getPageWidth() - 2 * $marginX;
        $pageHeight = $pdf->getPageHeight() - $top - 15;

        $scale     = min($pageWidth / $imgWidth, $pageHeight / $imgHeight);
        $newWidth  = $imgWidth * $scale;
        $newHeight = $imgHeight * $scale;
        $x         = $marginX + ($pageWidth - $newWidth) / 2;

        $type = strtoupper((string) pathinfo($file->originalName, PATHINFO_EXTENSION));
        if ($type === 'JPG') {
            $type = 'JPEG';
        }

        $pdf->Image('@' . $content, $x, $top, $newWidth, $newHeight, $type);
    }

    private function appendPlaceholderPage(Fpdi $pdf, ContractApplicationFileDto $file, string $reason): void
    {
        $pdf->AddPage();
        $this->writeFileCaption($pdf, $file);

        $pdf->SetFont(self::FONT, '', 10);
        $pdf->SetTextColor(120, 120, 120);
        $pdf->MultiCell(0, 6, $reason, 0, 'L');
        $pdf->SetTextColor(0, 0, 0);
    }

    private function writeFileCaption(Fpdi $pdf, ContractApplicationFileDto $file): void
    {
        $pdf->SetFont(self::FONT, 'B', 12);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->MultiCell(0, 7, 'Вложение: ' . $file->originalName, 0, 'L');
        $pdf->SetFont(self::FONT, '', 9);
        $pdf->SetTextColor(120, 120, 120);
        $pdf->MultiCell(0, 5, $file->getFileSizeFormatted(), 0, 'L');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(1);
    }

    private function writeTitle(Fpdi $pdf, string $title): void
    {
        $pdf->SetFont(self::FONT, 'B', 16);
        $pdf->MultiCell(0, 10, $title, 0, 'L');
        $pdf->Ln(2);
    }

    /**
     * @param array<string, string|null> $rows
     */
    private function writeSection(Fpdi $pdf, string $heading, array $rows): void
    {
        $rows = array_filter($rows, static fn($v) => $v !== null && $v !== '');
        if (\count($rows) === 0) {
            return;
        }

        $pdf->Ln(2);
        $pdf->SetFont(self::FONT, 'B', 12);
        $pdf->MultiCell(0, 7, $heading, 0, 'L');
        $pdf->SetDrawColor(200, 200, 200);
        $y = $pdf->GetY();
        $pdf->Line(15, $y, 195, $y);
        $pdf->Ln(1);

        $this->writeRows($pdf, $rows);
    }

    /**
     * @param array<string, string|null> $rows
     */
    private function writeRows(Fpdi $pdf, array $rows): void
    {
        $labelWidth = 55;
        $valueWidth = 125;

        foreach ($rows as $label => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $startY = $pdf->GetY();
            $startX = $pdf->GetX();

            $pdf->SetFont(self::FONT, '', 10);
            $pdf->SetTextColor(120, 120, 120);
            $pdf->MultiCell($labelWidth, 6, (string) $label, 0, 'L', false, 0);

            $pdf->SetTextColor(0, 0, 0);
            $pdf->MultiCell($valueWidth, 6, (string) $value, 0, 'L', false, 1);

            $endY = $pdf->GetY();
            if ($endY - $startY < 6) {
                $pdf->SetXY($startX, $startY + 6);
            }
        }
    }

    /**
     * @param array<int, string|null> $parts
     */
    private function joinNonEmpty(array $parts, string $glue): ?string
    {
        $filtered = array_values(array_filter($parts, static fn($v) => $v !== null && $v !== ''));
        return $filtered === [] ? null : implode($glue, $filtered);
    }

    private function signerTypeLabel(?string $v): ?string
    {
        return match ($v) {
            'director'       => 'Руководитель',
            'representative' => 'Представитель по доверенности',
            null, ''         => null,
            default          => $v,
        };
    }

    private function deliveryMethodLabel(?string $v): ?string
    {
        return match ($v) {
            'edo'     => 'ЭДО',
            'mail'    => 'Почта',
            'courier' => 'Курьер',
            'pickup'  => 'Самовывоз',
            null, ''  => null,
            default   => $v,
        };
    }

    private function objectCategoryLabel(?string $v): ?string
    {
        return match ($v) {
            'commercial'  => 'Коммерческая',
            'residential' => 'Жилая',
            'industrial'  => 'Промышленная',
            null, ''      => null,
            default       => $v,
        };
    }

    private function ownershipLabel(?string $v): ?string
    {
        return match ($v) {
            'own'    => 'Собственность',
            'rent'   => 'Аренда',
            null, '' => null,
            default  => $v,
        };
    }

    private function containerOwnershipLabel(?string $v): ?string
    {
        return match ($v) {
            'operator' => 'Оператора',
            'own'      => 'Собственные',
            null, ''   => null,
            default    => $v,
        };
    }

    private function containerMaterialLabel(?string $v): ?string
    {
        return match ($v) {
            'metal'   => 'Металл',
            'plastic' => 'Пластик',
            null, ''  => null,
            default   => $v,
        };
    }

    private function containerScheduleLabel(?string $v): ?string
    {
        return match ($v) {
            'daily'      => 'Ежедневно',
            'twice_week' => '2 раза в неделю',
            'once_week'  => '1 раз в неделю',
            null, ''     => null,
            default      => $v,
        };
    }
}
