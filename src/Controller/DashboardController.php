<?php

namespace App\Controller;

use App\Dashboard\DashboardStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly DashboardStore $store,
    ) {
    }

    #[Route('/', name: 'dashboard', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $batchId = $request->query->get('batch');

        // Seed the Vue app with the initial state so the first paint has data,
        // the same role Inertia props played in the original.
        return $this->render('dashboard.html.twig', [
            'initialState' => [
                'batchId' => $batchId,
                'stats' => $this->store->stats($batchId),
                'jobs' => $batchId ? $this->store->batchJobs($batchId) : [],
                'recentBatches' => $this->store->recentBatches(),
            ],
        ]);
    }
}
