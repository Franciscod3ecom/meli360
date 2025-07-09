<?php
/**
 * Helper para logging de eventos e erros na aplicação.
 */

if (!function_exists('log_message')) {
    /**
     * Escreve uma mensagem em um arquivo de log com timestamp.
     *
     * @param string $message A mensagem a ser registrada.
     * @param string $level O nível do log (ex: INFO, ERROR, DEBUG). Padrão é INFO.
     * @return void
     */
    function log_message(string $message, string $level = 'INFO'): void
    {
        try {
            // Garante que a constante BASE_PATH está definida para um caminho de log consistente.
            if (!defined('BASE_PATH')) {
                // Fallback para o caso da constante não estar definida, mas loga um erro.
                error_log("Constante BASE_PATH não definida. O caminho do log pode estar incorreto.");
                $logFilePath = dirname(__DIR__, 2) . '/app.log';
            } else {
                $logFilePath = BASE_PATH . '/app.log';
            }

            // Define o caminho para o arquivo de log.
            // Formata a mensagem com data, nível e PID (Process ID)
            $timestamp = date('Y-m-d H:i:s');
            $pid = getmypid() ?: 'N/A';
            $logLine = "[$timestamp] [$level] [PID:$pid] $message\n";

            // Escreve no arquivo de log, adicionando ao final (FILE_APPEND)
            // LOCK_EX tenta evitar que múltiplas requisições escrevam ao mesmo tempo e corrompam o arquivo.
            file_put_contents($logFilePath, $logLine, FILE_APPEND | LOCK_EX);
        } catch (\Exception $e) {
            // Se até o log falhar, registra no log de erros padrão do PHP.
            error_log("Falha CRÍTICA no sistema de log: " . $e->getMessage());
            error_log("Mensagem original que falhou ao logar: " . $message);
        }
    }
}