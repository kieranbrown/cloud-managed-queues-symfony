<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Attribute\Route;

class DefaultController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse([
            'app' => 'cloud-managed-queues-symfony',
            'symfony' => Kernel::VERSION,
            'status' => 'ok',
        ]);
    }
}
