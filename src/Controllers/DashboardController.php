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

        // Busca todos os anúncios do usuário logado
        $anuncios = $anuncioModel->findAllBySaasUserId($saasUserId);
        
        // Busca todas as conexões do Mercado Livre para o usuário logado
        $mlConnections = $mlUserModel->findAllBySaasUserId($saasUserId);

        // Passa os dados para a view
        view('dashboard.analysis', [
            'anuncios' => $anuncios,
            'mlConnections' => $mlConnections
        ]);
    }
}