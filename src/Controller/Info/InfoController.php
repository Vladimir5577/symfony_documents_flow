<?php

namespace App\Controller\Info;

use App\Enum\Info\InfoVideo;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

final class InfoController extends AbstractController
{
    #[Route('/instruction', name: 'app_instruction')]
    public function index(): Response
    {
        $videos = array_map(
            static fn (InfoVideo $video): array => [
                'key' => $video->value,
                'label' => $video->label(),
            ],
            InfoVideo::cases()
        );

        return $this->render('info/index.html.twig', [
            'active_tab' => 'info',
            'videos' => $videos,
        ]);
    }

    #[Route('/instruction/video/{video}', name: 'app_instruction_video', methods: ['GET'])]
    public function viewVideo(string $video): Response
    {
        $selectedVideo = InfoVideo::tryFrom($video);
        if (!$selectedVideo instanceof InfoVideo) {
            throw $this->createNotFoundException('Видео не найдено.');
        }

        return $this->render('info/view_video.html.twig', [
            'active_tab' => 'info',
            'video' => [
                'key' => $selectedVideo->value,
                'label' => $selectedVideo->label(),
            ],
        ]);
    }

    #[Route('/instruction/video-file/{video}', name: 'app_instruction_video_file', methods: ['GET'])]
    public function videoFile(string $video, #[Autowire('%private_upload_dir%')] string $privateUploadDir): Response
    {
        $selectedVideo = InfoVideo::tryFrom($video);
        if (!$selectedVideo instanceof InfoVideo) {
            throw $this->createNotFoundException('Видео не найдено.');
        }

        $path = rtrim($privateUploadDir, \DIRECTORY_SEPARATOR)
            . \DIRECTORY_SEPARATOR
            . 'videos'
            . \DIRECTORY_SEPARATOR
            . $selectedVideo->value;

        if (!is_file($path)) {
            throw $this->createNotFoundException('Файл видео не найден.');
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $selectedVideo->value);

        return $response;
    }
}
