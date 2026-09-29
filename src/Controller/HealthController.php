<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController extends AbstractController
{
    #[Route('/healthz', name: 'health', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('health.html.twig');
    }
}
