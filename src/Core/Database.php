<?php
/**
 * Classe responsável pela conexão com o banco de dados.
 * Utiliza o padrão Singleton para garantir que exista apenas uma instância
 * da conexão PDO durante todo o ciclo de vida da requisição.
 */

namespace App\Core;

use PDO;
use PDOException;

class Database
{
    /**
     * @var PDO|null A única instância da conexão PDO.
     */
    private static ?PDO $instance = null;

    /**
     * O construtor é privado para impedir a criação de novas instâncias
     * com o operador 'new'.
     */
    private function __construct() {}

    /**
     * Impede a clonagem da instância (padrão Singleton).
     */
    private function __clone() {}

    /**
     * Impede a desserialização da instância (padrão Singleton).
     */
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize a singleton.");
    }

    /**
     * Método estático que controla o acesso à instância PDO.
     * Cria a conexão na primeira chamada e a retorna nas chamadas subsequentes.
     *
     * @return PDO A instância da conexão PDO.
     * @throws PDOException Se a conexão com o banco de dados falhar.
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            // Obtém as credenciais das variáveis de ambiente
            $host = getenv('DB_HOST');
            $db   = getenv('DB_DATABASE');
            $user = getenv('DB_USERNAME');
            $pass = getenv('DB_PASSWORD');

            if ($host === false || $db === false || $user === false || $pass === false) {
                error_log('Erro Crítico: As variáveis de ambiente do banco de dados não estão definidas no arquivo .env.');
                throw new PDOException('Erro de configuração do servidor.');
            }

            // String de Conexão (DSN - Data Source Name)
            $dsn = "mysql:host={$host};dbname={$db};charset=utf8mb4";

            // Opções da conexão PDO para performance e segurança
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Lança exceções em caso de erro SQL
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Retorna resultados como arrays associativos
                PDO::ATTR_EMULATE_PREPARES   => false,                  // Usa prepared statements nativos do MySQL
            ];

            try {
                // Tenta criar a instância da conexão PDO
                self::$instance = new PDO($dsn, $user, $pass, $options);
            } catch (PDOException $e) {
                // Em caso de falha na conexão, para a execução e exibe uma mensagem genérica.
                // Loga o erro detalhado e relança a exceção para que um handler de erro global possa tratá-la.
                error_log("Falha na conexão com o banco de dados: " . $e->getMessage());
                // Lançar a exceção permite que um error handler global capture o erro e mostre uma página amigável.
                throw new PDOException('Erro: Não foi possível conectar ao banco de dados.', (int)$e->getCode(), $e);
            }
        }

        // Retorna a instância PDO existente ou recém-criada.
        return self::$instance;
    }
}