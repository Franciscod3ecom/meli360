Repositório do Projeto (Fonte da Verdade): https://github.com/Franciscod3ecom/meli360
1. Visão Geral e Objetivo Principal
Estamos construindo uma plataforma SaaS (Software as a Service) profissional e escalável para análise e automação de contas do Mercado Livre. O sistema é multi-tenant e suporta múltiplos usuários, onde cada usuário (saas_user) pode conectar uma ou mais contas do Mercado Livre.
Funcionalidades-Chave:
Análise de Anúncios Profunda: Extração de dados detalhados de anúncios (fretes, atributos, saúde, métricas).
Respondedor Automático com IA: Uso do Google Gemini para responder perguntas de clientes.
Gestão de Assinaturas e Cobrança: Integração com a API Asaas, com planos que podem variar com base no número de contas ML conectadas.
Múltiplos Níveis de Acesso: Suporte para user, consultant, e admin.
user: Cliente final, acessa apenas suas próprias contas conectadas.
consultant: Gerencia uma carteira de clientes (user) e pode personificá-los.
admin: Acesso irrestrito ao sistema e gerenciamento de todos os usuários.
2. Princípios Arquiteturais e de Desenvolvimento (Inflexíveis)
Arquitetura: Front Controller (MVC-like) com ponto de entrada em public/index.php.
Roteamento: Centralizado em src/routes.php usando bramus/router.
Tecnologia: PHP >= 8.1 (OOP), Tailwind CSS, Alpine.js, MySQL/MariaDB (PDO).
Padrões: PSR-12, Composer, .env para segredos, defuse/php-encryption para criptografia.
3. Documentação de APIs (Fonte da Verdade Externa)
Mercado Livre: https://developers.mercadolivre.com.br/pt_br
Google Gemini: https://ai.google.dev/docs
Asaas: https://docs.asaas.com/docs
Evolution API (WhatsApp): [Consultar documentação da instância específica]
4. Estrutura de Banco de Dados Principal
saas_users: (id, email, password_hash, name, role ENUM('user', 'consultant', 'admin'), etc.)
mercadolibre_users: (id, saas_user_id, ml_user_id, nickname, access_token (criptografado), etc.).
anuncios: (id, saas_user_id, ml_user_id, ml_item_id, title, etc.)
consultant_clients: (id, consultant_id, client_id).
question_processing_log: (id, ml_question_id, etc.).
