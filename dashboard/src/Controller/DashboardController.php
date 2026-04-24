<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        return $this->render('dashboard/home.html.twig', [
            'period_label' => 'Depuis le 1er du mois',
            'previous_period_label' => 'Même période N-1',
            'kpis' => [
                [
                    'label' => 'CA mensuel',
                    'value' => 'À brancher',
                    'hint' => 'Somme des factures sur le mois en cours',
                ],
                [
                    'label' => 'Marge mensuelle',
                    'value' => 'À brancher',
                    'hint' => 'Marge brute sur la même période',
                ],
                [
                    'label' => 'Factures',
                    'value' => 'À brancher',
                    'hint' => 'Nombre de factures sur le mois',
                ],
                [
                    'label' => 'Panier moyen',
                    'value' => 'À brancher',
                    'hint' => 'CA moyen par facture',
                ],
            ],
            'channels' => [
                [
                    'label' => 'Web',
                    'value' => 'À brancher',
                    'hint' => 'CA e-commerce et tunnel digital',
                ],
                [
                    'label' => 'Boutique',
                    'value' => 'À brancher',
                    'hint' => 'Ventes comptoir / magasin',
                ],
                [
                    'label' => 'B2B',
                    'value' => 'À brancher',
                    'hint' => 'Comptes pros et commandes directes',
                ],
                [
                    'label' => 'Autre',
                    'value' => 'À brancher',
                    'hint' => 'Canaux à qualifier dans `K_Li_FAC`',
                ],
            ],
        ]);
    }
}
