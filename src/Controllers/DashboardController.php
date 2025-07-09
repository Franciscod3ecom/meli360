<?php
namespace App\Controllers;

use App\Models\MercadoLivreUser;
use App\Models\Anuncio;

class DashboardController
{
    /**
     * Exibe a página principal de "Visão Geral" do dashboard.
     */
    public function index(): void
    {
        $mlUserModel = new MercadoLivreUser();
        $mlConnections = $mlUserModel->findAllBySaasUserId($_SESSION['user_id']);
        
        require_once BASE_PATH . '/src/Views/dashboard/index.phtml';
    }

    /**
     * Exibe a página de "Análise de Anúncios".
     */
    public function analysis(): void
    {
        $saasUserId = $_SESSION['user_id'];
        
        $anuncioModel = new Anuncio();
        $mlUserModel = new MercadoLivreUser();

        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = 50;
        $offset = ($page > 0) ? ($page - 1) * $limit : 0;

        $anuncios = $anuncioModel->findAllByUserId($saasUserId, $limit, $offset);
        $totalAnuncios = $anuncioModel->countByUserId($saasUserId);
        $totalPages = ceil($totalAnuncios / $limit);
        
        $mlConnections = $mlUserModel->findAllBySaasUserId($saasUserId);
        $syncStatus = $mlConnections[0]['sync_status'] ?? 'IDLE';
        $syncMessage = $mlConnections[0]['sync_last_message'] ?? '';

        require_once BASE_PATH . '/src/Views/dashboard/analysis.phtml';
    }
}