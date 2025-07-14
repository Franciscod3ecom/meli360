<?php

if (!function_exists('view')) {
    /**
     * Carrega um arquivo de view, o envolve com um layout padrão e passa dados para ele.
     *
     * @param string $path O caminho da view a partir de /src/Views/ (ex: 'dashboard.index').
     * @param array $data Dados a serem extraídos como variáveis na view.
     * @param string|null $layout O layout a ser usado. Se null, nenhum layout é usado.
     */
    function view(string $path, array $data = [], ?string $layout = 'default'): void
    {
        $viewPath = BASE_PATH . '/src/Views/' . str_replace('.', '/', $path) . '.phtml';
        if (!file_exists($viewPath)) {
            die("Erro Crítico: Arquivo de View não encontrado em: " . htmlspecialchars($viewPath));
        }
        
        extract($data);

        if ($layout) {
            $layoutPath = BASE_PATH . '/src/Views/layouts/' . $layout . '.phtml';
            if (!file_exists($layoutPath)) {
                die("Erro Crítico: Arquivo de Layout não encontrado em: " . htmlspecialchars($layoutPath));
            }
            // O layout agora é responsável por incluir o $viewPath
            require $layoutPath;
        } else {
            // Comportamento antigo: apenas requer a view
            require $viewPath;
        }
    }
}