<?php
namespace App\Core;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;

    private function __construct() {}
    private function __clone() {}
    public function __wakeup() { throw new \Exception("Cannot unserialize a singleton."); }

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            // Log de tentativa de conexão
            log_message("Database::getInstance() - Tentando estabelecer nova conexão PDO...", "DEBUG");

            if (!defined('DB_HOST') || !defined('DB_DATABASE') || !defined('DB_USERNAME') || !defined('DB_PASSWORD')) {
                die('Erro Crítico: Constantes do banco de dados não definidas. Verifique se o config.php está sendo incluído e se o .env está sendo carregado.');
            }

            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_DATABASE . ';port=' . DB_PORT . ';charset=utf8mb4';
            
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$instance = new PDO($dsn, DB_USERNAME, DB_PASSWORD, $options);
                // Log de sucesso na conexão
                log_message("Database::getInstance() - Conexão PDO estabelecida com sucesso.", "DEBUG");
            } catch (PDOException $e) {
                // =====================================================================
                // MUDANÇA CRÍTICA PARA DEPURAÇÃO
                // Em vez de uma mensagem genérica, vamos exibir o erro real do PDO.
                // Isso nos dirá se é "Access denied", "Unknown database", "Connection refused", etc.
                // =====================================================================
                
                // Primeiro, logamos o erro para registro futuro
                error_log("FALHA NA CONEXÃO PDO: " . $e->getMessage());

                // Em seguida, exibimos uma mensagem detalhada para depuração
                die(
                    "<h1>Erro de Conexão com o Banco de Dados</h1>" .
                    "<p>Não foi possível estabelecer uma conexão com o MySQL.</p>" .
                    "<p><strong>Mensagem de Erro do Servidor:</strong> <pre style='background-color: #f2f2f2; border: 1px solid #ccc; padding: 10px;'>" . htmlspecialchars($e->getMessage()) . "</pre></p>" .
                    "<p><strong>Verifique os seguintes pontos:</strong></p>" .
                    "<ul>" .
                    "<li>As credenciais (Host, Nome do Banco, Usuário, Senha) no seu arquivo <strong>.env</strong> estão 100% corretas?</li>" .
                    "<li>O host do banco de dados na sua hospedagem é realmente 'localhost'? Tente usar '127.0.0.1'.</li>" .
                    "<li>O usuário do banco de dados tem permissão para acessar a partir deste servidor?</li>" .
                    "</ul>"
                );
            }
        }
        return self::$instance;
    }
}