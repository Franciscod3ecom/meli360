<?php
namespace App\Controllers;

use App\Models\Anuncio;
use App\Models\MercadoLivreUser;

class DashboardController
{
    /**
     * Exibe a página principal de "Visão Geral" do dashboard.
     */
    public function index(): void
    {
        $mlUserModel = new MercadoLivreUser();
        $mlConnections = $mlUserModel->findAllBySaasUserId($_SESSION['user_id']);
        
        view('dashboard.index', ['mlConnections' => $mlConnections]);
    }

    /**
     * Exibe a página de "Análise de Anúncios".
     */
    public function analysis(): void
    {
        $saasUserId = $_SESSION['user_id'];
        $activeMlAccountId = $_SESSION['active_ml_account_id'] ?? null;

        $anuncioModel = new Anuncio();
        $mlUserModel = new MercadoLivreUser();

        // Busca todas as contas conectadas para o menu de sincronização
        $mlConnections = $mlUserModel->findAllBySaasUserId($saasUserId);

        $anuncios = [];
        $totalAnuncios = 0;
        $totalPages = 0;
        $page = 1;
        $limit = 50;
        $offset = 0;

        // Apenas busca anúncios se uma conta estiver ativa na sessão
        if ($activeMlAccountId) {
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $offset = ($page > 0) ? ($page - 1) * $limit : 0;
            $anuncios = $anuncioModel->findAllByMlUserId($saasUserId, $activeMlAccountId, $limit, $offset);
            $totalAnuncios = $anuncioModel->countByMlUserId($saasUserId, $activeMlAccountId);
            $totalPages = ceil($totalAnuncios / $limit);
        }
        
        view('dashboard.analysis', [
            'anuncios' => $anuncios,
            'mlConnections' => $mlConnections,
            'activeMlAccountId' => $activeMlAccountId,
            'totalAnuncios' => $totalAnuncios,
            'totalPages' => $totalPages,
            'currentPage' => $page
        ]);
    }

    /**
     * Exibe a página do "Respondedor IA" com o histórico de perguntas.
     */
    public function responder(): void
    {
        $saasUserId = $_SESSION['user_id'];
        $questionModel = new \App\Models\Question();
        $questions = $questionModel->findAllBySaasUserId($saasUserId);

        view('dashboard.responder', ['questions' => $questions]);
    }
}