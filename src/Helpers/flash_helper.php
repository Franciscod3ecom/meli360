<?php
/**
 * Helper para gerenciar mensagens flash na sessão.
 * Mensagens flash são exibidas uma única vez e depois removidas.
 */

if (!function_exists('set_flash_message')) {
    /**
     * Define uma mensagem flash na sessão.
     *
     * @param string $key A chave para a mensagem (ex: 'error', 'success').
     * @param string $message A mensagem a ser exibida.
     * @param string $type O tipo de mensagem para estilização CSS (ex: 'danger', 'success', 'info').
     * @return void
     */
    function set_flash_message(string $key, string $message, string $type = 'danger'): void
    {
        // Garante que a sessão foi iniciada.
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['flash_messages'][$key] = [
            'message' => $message,
            'type' => $type
        ];
    }
}

if (!function_exists('display_flash_message')) {
    /**
     * Exibe uma mensagem flash se ela existir e a remove da sessão.
     *
     * @param string $key A chave da mensagem a ser exibida.
     * @return void
     */
    function display_flash_message(string $key): void
    {
        if (isset($_SESSION['flash_messages'][$key])) {
            $flash = $_SESSION['flash_messages'][$key];
            
            // Mapeia o tipo da mensagem para as classes de cor do Tailwind CSS
            $colorClasses = [
                'danger'  => 'bg-red-100 border-red-400 text-red-700',
                'success' => 'bg-green-100 border-green-400 text-green-700',
                'info'    => 'bg-blue-100 border-blue-400 text-blue-700',
            ];

            $cssClasses = $colorClasses[$flash['type']] ?? $colorClasses['info'];

            // Exibe a mensagem com classes CSS para estilização com Tailwind CSS
            echo '<div class="border px-4 py-3 rounded relative ' . $cssClasses . '" role="alert">' . htmlspecialchars($flash['message']) . '</div>';
            // Remove a mensagem da sessão para que não seja exibida novamente.
            unset($_SESSION['flash_messages'][$key]);
        }
    }
}