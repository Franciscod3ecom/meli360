<?php
/**
 * Ponto de entrada para carregar todos os arquivos de helper da aplicação.
 * Este arquivo é incluído uma única vez no bootstrap da aplicação (index.php).
 */

// Carrega o helper de log
require_once __DIR__ . '/Helpers/log_helper.php';

// Carrega o helper de criptografia
require_once __DIR__ . '/Helpers/encryption_helper.php';

// Carrega o helper para renderização de views
require_once __DIR__ . '/Helpers/view_helper.php';

// Carrega o helper de CSRF
require_once __DIR__ . '/Helpers/csrf_helper.php';

// Carrega o helper de mensagens flash
require_once __DIR__ . '/Helpers/flash_helper.php';

// Adicione outros helpers aqui conforme forem criados...