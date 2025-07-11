<?php
/**
 * Helper para gerenciar mensagens flash na sessão.
 * Mensagens flash são exibidas uma única vez e depois removidas.
 */

if (!function_exists('set_flash_message')) {
    /**
     * Define uma mensagem flash na sessão.
     *
     * @param string $key A chave para a mensagem (ex: 'auth_error', 'logout_success').
     * @param string $message A mensagem a ser exibida.
     */
    function set_flash_message(string $key, string $message): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['flash_messages'][$key] = $message;
    }
}

if (!function_exists('has_flash_message')) {
    /**
     * Verifica se uma mensagem flash existe na sessão.
     *
     * @param string $key A chave da mensagem.
     * @return bool
     */
    function has_flash_message(string $key): bool
    {
        return isset($_SESSION['flash_messages'][$key]);
    }
}

if (!function_exists('get_flash_message')) {
    /**
     * Obtém uma mensagem flash e a remove da sessão.
     *
     * @param string $key A chave da mensagem.
     * @return string|null A mensagem, ou null se não existir.
     */
    function get_flash_message(string $key): ?string
    {
        if (has_flash_message($key)) {
            $message = $_SESSION['flash_messages'][$key];
            unset($_SESSION['flash_messages'][$key]);
            return $message;
        }
        return null;
    }
}