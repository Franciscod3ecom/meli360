<?php

if (!function_exists('view')) {
    /**
     * Carrega um arquivo de view e passa dados para ele.
     *
     * @param string $path O caminho da view a partir de /src/Views/ (ex: 'dashboard.index').
     * @param array $data Dados a serem extraídos como variáveis na view.
     */
    function view(string $path, array $data = []): void
    {
        $viewPath = BASE_PATH . '/src/Views/' . str_replace('.', '/', $path) . '.phtml';
        if (!file_exists($viewPath)) {
            die("Erro Crítico: Arquivo de View não encontrado em: " . htmlspecialchars($viewPath));
        }
        extract($data);
        require $viewPath;
    }
}