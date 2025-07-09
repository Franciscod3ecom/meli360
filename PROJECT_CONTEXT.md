Contexto Mestre do Projeto: Plataforma SaaS MELI 360
Este documento serve como a fonte única da verdade para o desenvolvimento da plataforma MELI 360. Toda e qualquer interação deve seguir estritamente as diretrizes aqui contidas.
1. Visão Geral e Objetivo Principal
Estamos construindo uma plataforma SaaS (Software as a Service) profissional e escalável para análise e automação de contas do Mercado Livre. O sistema é multi-tenant e suporta múltiplos usuários, onde cada usuário (saas_user) pode conectar uma ou mais contas do Mercado Livre.
Funcionalidades-Chave:
Análise de Anúncios Profunda: Extração de dados detalhados de anúncios (fretes, atributos, saúde, métricas).
Respondedor Automático com IA: Uso do Google Gemini para responder perguntas de clientes.
Gestão de Assinaturas e Cobrança: Integração com a API Asaas, com planos que podem variar com base no número de contas ML conectadas.
Múltiplos Níveis de Acesso: Suporte para user, consultant, e admin.
user: Cliente final, acessa apenas suas próprias contas conectadas.
consultant: Gerencia uma carteira de clientes (user) e pode personificá-los para visualizar seus dashboards.
admin: Acesso irrestrito ao sistema, gerenciamento de todos os usuários e personificação de qualquer conta.
2. Princípios Arquiteturais e de Desenvolvimento (Inflexíveis)
Ponto de Entrada Único: Todas as requisições web são direcionadas para public/index.php. A pasta public/ é o único diretório exposto.
Roteamento Centralizado: Usamos bramus/router. As rotas são definidas em src/routes.php.
Separação de Responsabilidades:
Models (src/Models/): Lógica de negócio e acesso a dados (DB e APIs).
Views (src/Views/): Templates de apresentação (HTML, PHTML). Devem conter o mínimo de PHP possível.
Controllers (src/Controllers/): Orquestração do fluxo: recebem a requisição, usam os Modelos para obter dados e carregam as Views, passando os dados necessários.
Backend: PHP >= 8.1, fortemente Orientado a Objetos.
Frontend: HTML5, Tailwind CSS, Alpine.js para interatividade.
Banco de Dados: MySQL/MariaDB com PDO e Prepared Statements em todas as queries.
Padrão de Código: PSR-12 (4 espaços para PHP, 2 para o resto).
Dependências: Gerenciadas exclusivamente via Composer.
Segredos: Utilizar arquivo .env na raiz do projeto (fora do web root) com vlucas/phpdotenv.
Criptografia: Criptografar tokens e dados sensíveis com defuse/php-encryption.
Validação e Sanitização: Validar rigorosamente todos os inputs e sanitizar todos os outputs (htmlspecialchars).
PHPDoc: Todas as classes, propriedades e métodos devem ter documentação PHPDoc completa.
Código Autodescritivo: Nomes de variáveis e métodos devem ser claros e explícitos.
3. Documentação de APIs (Fonte da Verdade)
Sempre valide as implementações contra a documentação oficial antes de gerar código.
Mercado Livre: https://developers.mercadolivre.com.br/pt_br
Google Gemini: https://ai.google.dev/docs
Asaas: https://docs.asaas.com/docs
Evolution API (WhatsApp): [Consultar documentação da instância específica]
4. Estrutura de Banco de Dados Principal
saas_users: (id, email, password_hash, name, role ENUM('user', 'consultant', 'admin'), is_active, asaas_customer_id, etc.)
mercadolibre_users: (id, saas_user_id, ml_user_id, nickname, access_token (criptografado), refresh_token (criptografado), sync_status, etc.). Permite múltiplas conexões por saas_user_id.
anuncios: (id, saas_user_id, ml_user_id, ml_item_id, title, price, stock, total_visits, total_sales, health, etc.)
consultant_clients: (id, consultant_id, client_id). Mapeia clientes para consultores.
question_processing_log: (id, ml_question_id, etc.). Histórico do respondedor AI.
5. Protocolo de Interação
Completude: SEMPRE forneça o conteúdo integral e atualizado dos arquivos solicitados. Não use abreviações ou trechos.
Justificativa: Ao corrigir ou refatorar, explique o "quê" e o "porquê" da mudança.
Proatividade: Se uma solicitação for ambígua ou arquiteturalmente incorreta com base neste documento, peça esclarecimentos.