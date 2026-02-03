<?php

namespace App\Controller\Document;

use App\Service\Document\FileUploadService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

final class DocumentCreateFromTemplateController extends AbstractController
{
    #[Route('/document_create_from_template', name: 'app_document_create_from_template')]
    public function index(Request $request): Response
    {
        $content = '
            <p>Прошу принять меня на работу.</p>
            <p>ФИО: Иванов Иван Иванович</p>
        ';

        // если форма отправлена, подставляем новый текст
        if ($request->isMethod('POST')) {
            $content = $request->request->get('content');
        }

        return $this->render('document_create_from_template/index.html.twig', [
            'content' => $content,
            'date' => (new \DateTime())->format('d.m.Y'),
        ]);
    }

    #[Route('/document_save_from_template', name: 'app_document_save_from_template')]
    public function saveFromTemplate(
        Request $request,
        FileUploadService $fileUploadService,
        #[Autowire('%private_upload_dir_documents_originals%')] string $originalsDir,
    ): Response
    {
        // Получаем HTML из POST
        $html = $request->request->get('html');

        if (!$html) {
            return $this->json(['status' => 'error', 'message' => 'HTML не передан']);
        }

        $filename = date('Y-m-d_His') . '_' . $fileUploadService->generateFileName() . '.html';
        $filepath = $originalsDir . \DIRECTORY_SEPARATOR . $filename;

        if (!is_dir($originalsDir)) {
            mkdir($originalsDir, 0755, true);
        }
        file_put_contents($filepath, $html);

        return $this->json([
            'status' => 'ok',
            'message' => 'Файл сохранён',
            'filename' => $filename,
        ]);
    }

//    #[Route('/document_save_from_template', name: 'app_document_save_from_template')]
//    public function saveFromTemplate(Request $request): Response
//    {
//        // Получаем HTML из POST
//        $html = $request->request->get('html');
//
//        if (!$html) {
//            return $this->json(['status' => 'error', 'message' => 'HTML не передан']);
//        }
//
//
//        return $this->render('document_create_from_template/index.html.twig', [
//            'date' => (new \DateTime())->format('d.m.Y'),
//        ]);
//    }
}
