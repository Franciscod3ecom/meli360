<?php
namespace App\Controllers;

class AdminController
{
    /**
     * Executa manualmente o script de sincronização de anúncios.
     * Esta rota deve ser protegida para ser acessível apenas por administradores.
     *
     * @return void
     */
    public function triggerSync(): void
    {
        // Define um cabeçalho para que o navegador exiba o texto como pré-formatado
        header('Content-Type: text/plain; charset=utf-8');

        echo "========================================\n";
        echo " EXECUTANDO SINCRONIZAÇÃO MANUALMENTE \n";
        echo "========================================\n\n";

        // Altera o limite de tempo de execução para o script não parar no meio
        set_time_limit(300); // 5 minutos

        // Usa 'include' para executar o script de cron como se estivéssemos na linha de comando.
        // Toda a saída (echos) do script será exibida na tela do navegador.
        try {
            include_once BASE_PATH . '/scripts/sync_listings.php';
        } catch (\Exception $e) {
            echo "\n\n========================================\n";
            echo " ERRO FATAL DURANTE A EXECUÇÃO \n";
            echo "========================================\n\n";
            echo "Erro: " . $e->getMessage() . "\n";
            echo "Arquivo: " . $e->getFile() . "\n";
            echo "Linha: " . $e->getLine() . "\n";
        }

        echo "\n\n--- Execução manual finalizada ---";
    }
}