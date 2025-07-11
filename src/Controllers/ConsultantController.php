<?php
namespace App\Controllers;

use App\Models\User;

class ConsultantController
{
    /**
     * Exibe o dashboard principal do consultor.
     * Lista todos os clientes associados a este consultor.
     */
    public function index(): void
    {
        $consultantId = $_SESSION['user_id'];
        
        $userModel = new User();
        // Este método precisará ser criado no modelo User
        $clients = $userModel->findClientsByConsultantId($consultantId);

        view('consultant.dashboard', ['clients' => $clients]);
    }
}
