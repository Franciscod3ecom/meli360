Blueprint & Roadmap Arquitetural: Plataforma SaaS MELI 360
Versão do Documento: 1.0
Última Atualização: 9 de Julho de 2025
Repositório Oficial (Fonte Única da Verdade do Código):
https://github.com/Franciscod3ecom/meli360
1. Visão Geral e Modelo de Negócio
O MELI 360 é uma plataforma SaaS (Software as a Service) profissional e escalável para análise e automação de contas do Mercado Livre. O sistema é construído sobre uma arquitetura multi-tenant, permitindo que cada usuário da plataforma (saas_user) conecte e gerencie uma ou mais contas do Mercado Livre.
1.1. Níveis de Acesso
user: O cliente final. Acessa apenas suas próprias contas do Mercado Livre conectadas.
consultant: Um consultor que gerencia uma carteira de clientes (user). Pode personificar seus clientes para visualizar seus dashboards e operar em seu nome.
admin: O administrador do sistema. Possui acesso irrestrito, pode gerenciar todos os usuários, assinaturas, e personificar qualquer conta no sistema.
1.2. Monetização
O sistema será monetizado através de um modelo de assinatura, com pagamentos recorrentes processados via Asaas.
Os planos poderão variar com base nas funcionalidades ativadas e/ou no número de contas do Mercado Livre conectadas por usuário.
1.3. Funcionalidades Chave
Análise de Anúncios Profunda: Extração de dados detalhados de anúncios, incluindo precificação de fretes, atributos de categoria, saúde do anúncio e métricas de desempenho.
Respondedor Automático com IA: Uso do Google Gemini para responder perguntas de clientes de forma inteligente.
Notificações Ativas: Envio de notificações para os usuários via WhatsApp, através da Evolution API.
2. Documentação de APIs e Tecnologias
Esta seção centraliza os links para todas as documentações externas que são a base do nosso desenvolvimento.
PHP: Documentação Oficial do PHP
Mercado Livre API: Portal de Desenvolvedores do Mercado Livre
Google Gemini API: Documentação do Google AI para Desenvolvedores
Asaas API: Documentação da API do Asaas
Evolution API (WhatsApp): [Consultar a documentação da instância específica em uso]
Tailwind CSS: Documentação do Tailwind CSS
Alpine.js: Documentação do Alpine.js
Composer: Gerenciador de Dependências para PHP
3. Arquitetura e Estrutura de Diretórios
A aplicação segue o padrão Front Controller com uma estrutura MVC-like.
Generated code
/meli360/
|
|-- /public/                  <-- Web Root. ÚNICO diretório acessível publicamente.
|   |-- .htaccess             # Redireciona todas as requisições para o index.php.
|   |-- index.php             # Front Controller. Ponto de entrada ÚNICO.
|
|-- /src/                     <-- Coração da aplicação.
|   |-- /Controllers/         # Orquestradores: recebem requisições, usam modelos, carregam views.
|   |-- /Models/              # Lógica de negócio e acesso a dados (DB e APIs).
|   |-- /Views/               # Templates de Apresentação (HTML/PHTML).
|   |-- /Core/                # Classes fundamentais do sistema.
|   |-- /Helpers/             # Funções auxiliares.
|   |-- routes.php            # Mapa de todas as URLs da aplicação.
|
|-- /scripts/                 # Scripts para execução via Cron Job (CLI).
|
|-- .env                      # Arquivo com senhas e chaves de API. NUNCA no Git.
|-- composer.json             # Define as dependências do projeto.
|-- PROJECT_BLUEPRINT.md      # Este arquivo.
|-- .gitignore                # Lista de arquivos e pastas a serem ignorados pelo Git.
Use code with caution.
4. Roadmap de Implementação e Migração
Esta seção detalha o que será construído e como as funcionalidades dos projetos antigos serão absorvidas.
✅ FASE ATUAL: Fundação e Autenticação (Concluída)
Status: Concluído.
Funcionalidade: Usuários podem se cadastrar, fazer login e conectar suas contas do Mercado Livre. O fluxo OAuth2 está completo e os tokens são armazenados de forma segura.
🔄 FASE 1: Sincronização e Análise de Anúncios (Em Andamento)
Objetivo: Implementar a importação completa dos dados dos anúncios.
Estratégia de Migração: Absorver a inteligência do "Analisador" e do "Meli 360 Sheets".
Componentes Chave:
scripts/sync_listings.php: O cron job que orquestra todo o processo.
Lógica de Execução:
Busca de IDs: Utiliza o endpoint /users/{user_id}/items/search com search_type=scan e scroll_id para obter a lista completa de IDs de anúncios de forma rápida e eficiente.
Detalhamento em Lotes: Após salvar os IDs, busca os anúncios com sync_status = 0 em lotes de 20. Para cada lote, faz uma única chamada ao endpoint /items?ids=... para buscar os detalhes, respeitando o limite da API.
Análise Detalhada: (A ser implementado) Para cada anúncio, fará chamadas adicionais a endpoints como /items/{item_id}/shipping_options e /categories/{category_id}/shipping_preferences para obter dados de frete e regras de categoria.
Models/Anuncio.php: Conterá os métodos para interagir com a API do ML (fetchAllItemIdsFromApi, fetchItemDetailsFromApi) e para salvar os dados no banco (bulkInsertIds, bulkUpdateDetails).
Views/dashboard/analysis.phtml: A tela que exibirá a tabela completa de anúncios com todos os dados sincronizados, filtros e paginação.
⏳ FASE 2: Respondedor com IA (Futuro)
Objetivo: Implementar o sistema de resposta automática de perguntas.
Estratégia de Migração: Absorver e refatorar a lógica do projeto "Meli AI".
Componentes a serem Criados:
Models/Question.php, Models/GeminiAPI.php, Models/EvolutionAPI.php.
Controllers/WebhookController.php com métodos para handleMercadoLivreWebhook e handleWhatsAppWebhook.
scripts/poll_questions.php para atuar como fallback e gerenciador de timeouts.
⏳ FASE 3: Gestão de Usuários e Níveis de Acesso (Futuro)
Objetivo: Construir a interface para os papéis de admin e consultant.
Componentes a serem Criados:
Controllers/AdminController.php com lógicas para listar/gerenciar usuários, atribuir clientes e personificar contas.
Controllers/ConsultantController.php com dashboard para visualização da carteira de clientes.
Views/admin/ e Views/consultant/ com as telas específicas.
⏳ FASE 4: Billing e Assinaturas (Futuro)
Objetivo: Integrar o sistema de pagamentos Asaas.
Estratégia de Migração: Absorver e refatorar a lógica de billing do projeto "Meli AI".
Componentes a serem Criados:
Models/AsaasAPI.php.
Controllers/BillingController.php.
Método handleAsaasWebhook no WebhookController.
Middleware de verificação de assinatura para proteger rotas e funcionalidades premium.
Este documento deve servir como nosso guia central. Qualquer dúvida sobre a arquitetura, o fluxo ou o próximo passo deve ser respondida consultando-o primeiro.