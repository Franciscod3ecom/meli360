<?php
/**
 * Helper para proteção contra ataques CSRF (Cross-Site Request Forgery).
 */

if (!function_exists('generate_csrf_token')) {
    /**
     * Gera e armazena um token CSRF na sessão, se não existir um.
     *
     * @param bool $force Regenera o token mesmo que já exista um. Útil após o login.
     * @return string O token CSRF.
     */
    function generate_csrf_token(bool $force = false): string
    {
        if ($force || !isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('validate_csrf_token')) {
    /**
     * Valida o token CSRF enviado contra o armazenado na sessão.
     * Usa hash_equals() para prevenir ataques de temporização.
     *
     * @param string|null $submittedToken O token enviado via formulário.
     * @return bool True se o token for válido, false caso contrário.
     */
    function validate_csrf_token(?string $submittedToken): bool
    {
        if (!isset($_SESSION['csrf_token']) || $submittedToken === null) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $submittedToken);
    }
}

if (!function_exists('generate_csrf_token_input')) {
    /**
     * Gera o campo de input HTML oculto com o token CSRF.
     *
     * @return void
     */
    function generate_csrf_token_input(): void
    {
        echo '<input type="hidden" name="csrf_token" value="' . generate_csrf_token() . '">';
    }
}