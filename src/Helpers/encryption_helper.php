<?php
/**
 * Helper para Criptografia usando defuse/php-encryption.
 */

use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;

if (!function_exists('load_encryption_key')) {
    /**
     * Carrega a chave de criptografia a partir da variável de ambiente.
     */
    function load_encryption_key(): Key
    {
        $keyAscii = $_ENV['APP_ENCRYPTION_KEY'] ?? '';
        if (empty($keyAscii)) {
            throw new \Exception('Chave de criptografia (APP_ENCRYPTION_KEY) não definida no .env.');
        }
        return Key::loadFromAsciiSafeString($keyAscii);
    }
}

if (!function_exists('encrypt_data')) {
    /**
     * Criptografa uma string.
     */
    function encrypt_data(string $data): string
    {
        return Crypto::encrypt($data, load_encryption_key());
    }
}

if (!function_exists('decrypt_data')) {
    /**
     * Descriptografa uma string.
     */
    function decrypt_data(string $data): string
    {
        return Crypto::decrypt($data, load_encryption_key());
    }
}