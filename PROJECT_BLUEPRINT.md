Repositório Oficial (Fonte da Verdade do Código):
https://github.com/Franciscod3ecom/meli360
1. Instrução Principal para a IA
Seu objetivo é auxiliar no desenvolvimento da plataforma MELI 360. Antes de gerar qualquer código, você DEVE se familiarizar com a arquitetura e o roadmap descritos neste documento. Quando for necessário implementar uma funcionalidade que existia em um dos projetos antigos, você DEVE consultar os arquivos de referência na pasta /docs para entender a lógica original e adaptá-la para a nova arquitetura MVC.
Arquivos de Referência Legados (Contexto Histórico):
Meli 360 (Google Sheets): /docs/PROJETO MELI 360 ANTIGO.md
Analisador de Anúncios: /docs/PROJETO ANALISADOR.md
Meli AI (Respondedor): /docs/MELIAI.md
2. Visão Geral e Modelo de Negócio
Construção de uma plataforma SaaS multi-tenant para análise e automação de contas do Mercado Livre, onde cada usuário (saas_user) pode conectar uma ou mais contas do ML.
Níveis de Acesso: user, consultant, admin.
Monetização: Billing via Asaas.
Funcionalidades Core: Análise Profunda de Anúncios e Respondedor com IA (Google Gemini).
3. Documentação de APIs e Tecnologias
PHP: Documentação Oficial
Mercado Livre API: Portal de Desenvolvedores
Google Gemini API: Documentação do Google AI
Asaas API: Documentação da API
Tailwind CSS: Documentação
Alpine.js: Documentação
4. Arquitetura e Estrutura de Diretórios (Planta Baixa)
A aplicação segue o padrão Front Controller com uma estrutura MVC-like.
Generated code
/meli360/
|
|-- /docs/                     # Documentação e Referência de Código Legado.
|   |-- MELIAI.md
|   |-- PROJETO ANALISADOR.md
|   |-- PROJETO MELI 360 ANTIGO.md
|
|-- /public/                   # Web Root. ÚNICO diretório acessível publicamente.
|   |-- .htaccess              # Redireciona todas as requisições para o index.php.
|   |-- index.php              # Front Controller (Ponto de entrada ÚNICO).
|
|-- /src/                      # Coração da aplicação.
|   |-- /Controllers/          # Orquestradores: recebem requisições, usam modelos, carregam views.
|   |-- /Models/               # Lógica de negócio e acesso a dados (DB e APIs).
|   |-- /Views/                # Templates de Apresentação (HTML/PHTML).
|   |-- /Core/                 # Classes fundamentais do sistema (Database, etc.).
|   |-- /Helpers/              # Funções auxiliares.
|   |-- routes.php             # Mapa de todas as URLs da aplicação.
|
|-- /scripts/                  # Scripts para execução via Cron Job (CLI).
|
|-- .env                       # Arquivo com senhas e chaves de API. (NUNCA no Git).
|-- composer.json              # Define as dependências do projeto.
|-- PROJECT_BLUEPRINT.md       # Este arquivo.
|-- .gitignore                 # Ignora arquivos e pastas (ex: .env, vendor/, docs/).
Use code with caution.
5. Roadmap de Implementação e Migração de Funcionalidades
✅ FASE ATUAL: Fundação e Autenticação (Concluída)
Status: Concluído.
Funcionalidade: Usuários podem se cadastrar, fazer login e conectar múltiplas contas do Mercado Livre. O fluxo OAuth2 está completo e os tokens são armazenados de forma segura.
🔄 FASE 1: Sincronização e Análise de Anúncios (Em Andamento)
Objetivo: Implementar a importação e o detalhamento completo dos dados dos anúncios.
Referência Legada Principal: PROJETO ANALISADOR.md (para a lógica de sincronização em duas fases) e PROJETO MELI 360 ANTIGO.md (para os tipos de dados a serem extraídos).
Componentes Chave a serem desenvolvidos:
scripts/sync_listings.php: Implementar a lógica de duas fases descrita no PROJETO ANALISADOR.md.
Busca de IDs: Usar a estratégia de scroll_id para obter todos os IDs de anúncios.
Detalhamento em Lotes: Usar o endpoint /items?ids=... em lotes de 20 para buscar detalhes básicos.
Análise Profunda: Para cada anúncio, fazer chamadas adicionais para obter dados de frete e categoria, replicando a lógica encontrada no Google Apps Script do PROJETO MELI 360 ANTIGO.md.
Models/Anuncio.php: Deve conter todos os métodos para suportar as ações acima (buscar, inserir e atualizar anúncios com todos os novos campos).
Views/dashboard/analysis.phtml: Deve ser refatorada para exibir todos os novos campos de dados (SKU, Estoque, Saúde, Fretes, etc.).
⏳ FASE 2: Respondedor com IA (Futuro)
Objetivo: Implementar o sistema de resposta automática de perguntas.
Referência Legada Principal: MELIAI.md.
Componentes a serem Criados:
Models/Question.php, Models/GeminiAPI.php, Models/EvolutionAPI.php.
Controllers/WebhookController.php.
scripts/poll_questions.php.
⏳ FASE 3: Gestão de Usuários e Níveis de Acesso (Futuro)
Objetivo: Construir a interface para os papéis de admin e consultant.
Referência Legada Principal: PROJETO ANALISADOR.md (para a lógica de impersonate).
Componentes a serem Criados:
Controllers/AdminController.php (com lógica de personificação).
Controllers/ConsultantController.php.
Views nas pastas /admin/ e /consultant/.
⏳ FASE 4: Billing e Assinaturas (Futuro)
Objetivo: Integrar o sistema de pagamentos Asaas.
Referência Legada Principal: MELIAI.md.
Componentes a serem Criados:
Models/AsaasAPI.php.
Controllers/BillingController.php.
Método handleAsaasWebhook no WebhookController.
Middleware de verificação de assinatura.
