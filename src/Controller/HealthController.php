<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController extends AbstractController
{
    /**
     * The compose healthcheck calls it every 30 s, so it stores no profile.
     */
    #[Route('/healthz', name: 'health', methods: ['GET'])]
    public function __invoke(#[Autowire(service: 'profiler')] ?Profiler $profiler): Response
    {
        $profiler?->disable();

        return $this->render('health.html.twig');
    }
}
