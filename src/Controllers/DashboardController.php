<?php
namespace App\Controllers;

use App\Models\Anuncio;
use App\Models\MercadoLivreUser;

class DashboardController
{
    /**
     * Exibe a página principal "Visão Geral", que agora é o hub central.
     * Lista todas as contas ML conectadas pelo usuário.
     */
    public function index(): void
    {
        $saasUserId = $_SESSION['user_id'];
        $mlUserModel = new MercadoLivreUser();
        
        // Busca todas as contas conectadas e suas estatísticas agregadas
        $mlConnections = $mlUserModel->findAllBySaasUserIdWithStats($saasUserId);
        
        view('dashboard.index', ['mlConnections' => $mlConnections]);
    }

    /**
     * Exibe a página de análise detalhada para uma conta ML específica.
     *
     * @param int $mlUserId O ID da conta do Mercado Livre a ser analisada.
     */
    public function accountAnalysis(int $mlUserId): void
    {
        $saasUserId = $_SESSION['user_id'];
        $mlUserModel = new MercadoLivreUser();
        
        // Valida se a conta pertence ao usuário logado
        $account = $mlUserModel->findByMlUserIdForUser($saasUserId, $mlUserId);
        if (!$account) {
            set_flash_message('error', 'Conta não encontrada ou não pertence a você.');
            header('Location: /dashboard');
            exit;
        }

        $anuncioModel = new Anuncio();
        $limit = 50;
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $offset = ($page > 0) ? ($page - 1) * $limit : 0;

        $anuncios = $anuncioModel->findAllByMlUserId($saasUserId, $mlUserId, $limit, $offset);
        $totalAnuncios = $anuncioModel->countByMlUserId($saasUserId, $mlUserId);
        $totalPages = ceil($totalAnuncios / $limit);
        
        $isSyncRunning = in_array($account['sync_status'], ['QUEUED', 'RUNNING']);

        view('dashboard.account_analysis', [
            'account' => $account,
            'anuncios' => $anuncios,
            'totalPages' => $totalPages,
            'currentPage' => $page,
            'isSyncRunning' => $isSyncRunning
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

    /**
     * Exibe a página de detalhes de um anúncio específico.
     *
     * @param string $mlItemId O ID do item no Mercado Livre.
     */
    public function anuncioDetails(string $mlItemId): void
    {
        $saasUserId = $_SESSION['user_id'];
        $anuncioModel = new Anuncio();
        
        $anuncio = $anuncioModel->findByMlItemId($mlItemId);

        // Validação: O anúncio existe e pertence ao usuário logado?
        if (!$anuncio || $anuncio['saas_user_id'] !== $saasUserId) {
            view('errors.404', ['message' => 'O anúncio que você está tentando acessar não foi encontrado ou não pertence à sua conta.']);
            return;
        }

        view('dashboard.anuncio_details', ['anuncio' => $anuncio]);
        exit();
    }

    /**
     * Solicita o início de uma nova sincronização para uma conta ML específica.
     * Este método é chamado quando o usuário clica no botão "Sincronizar".
     *
     * @param int $mlUserId O ID do usuário no Mercado Livre.
     */
    public function requestSync(int $mlUserId): void
    {
        $saasUserId = $_SESSION['user_id'];
        $mlUserModel = new \App\Models\MercadoLivreUser();

        // Verifica se a conta pertence ao usuário logado
        if ($mlUserModel->doesAccountBelongToUser($saasUserId, $mlUserId)) {
            // Coloca a conta na fila para o próximo ciclo do cron job
            $success = $mlUserModel->updateSyncStatusByMlUserId($mlUserId, 'QUEUED', 'Sincronização solicitada pelo usuário.');
            
            if ($success) {
                set_flash_message('success', 'Sincronização solicitada! A atualização dos dados começará em breve.');
            } else {
                set_flash_message('error', 'Ocorreu um erro ao solicitar a sincronização. Tente novamente.');
            }
        } else {
            set_flash_message('error', 'Você não tem permissão para sincronizar esta conta.');
        }

        // Redireciona de volta para a página de análise
        header('Location: /dashboard/analysis');
        exit();
    }
}