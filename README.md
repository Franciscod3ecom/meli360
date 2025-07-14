# 🚀 MELI 360 - Plataforma de Análise e Automação para Mercado Livre

**MELI 360** é uma plataforma SaaS (Software as a Service) projetada para vendedores do Mercado Livre, oferecendo ferramentas avançadas de análise de anúncios, automação de respostas com Inteligência Artificial e gerenciamento de contas.

[![PHP Version](https://img.shields.io/badge/php-%3E=8.1-8892BF.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](/LICENSE)

---

## ✨ Funcionalidades Principais

- **Dashboard Centralizado:** Uma visão geral de todas as suas contas do Mercado Livre conectadas, com estatísticas agregadas.
- **Análise de Anúncios:** Mergulhe nos detalhes de cada anúncio, com dados de saúde, vendas, estoque e informações de frete.
- **Sincronização em Fases:** Um sistema de fila robusto que importa e detalha milhares de anúncios em segundo plano, respeitando os limites da API do Mercado Livre.
- **Análise Profunda:** Obtenha insights valiosos com dados de frete para diferentes regiões e regras específicas de cada categoria de produto.
- **Painel Administrativo:** Ferramentas para administradores gerenciarem usuários, incluindo a capacidade de "personificar" um usuário para fins de suporte.
- **Segurança:** Autenticação segura, armazenamento de senhas com hash e proteção de rotas sensíveis.

## 🛠️ Tecnologias Utilizadas

- **Backend:** PHP 8.1+
- **Banco de Dados:** MySQL / MariaDB
- **Roteamento:** `bramus/router`
- **Variáveis de Ambiente:** `vlucas/phpdotenv`
- **Criptografia:** `defuse/php-encryption`
- **Frontend:** Tailwind CSS para estilização.

## ⚙️ Instalação e Configuração

Siga os passos abaixo para configurar o ambiente de desenvolvimento local.

### 1. Pré-requisitos

- PHP 8.1 ou superior
- Composer
- Servidor web local (Apache, Nginx) ou o servidor embutido do PHP
- MySQL ou MariaDB

### 2. Clone o Repositório

```bash
git clone https://github.com/Franciscod3ecom/meli360.git
cd meli360
```

### 3. Instale as Dependências

Execute o Composer para instalar as bibliotecas necessárias.

```bash
composer install
```

### 4. Configure o Ambiente

Copie o arquivo de exemplo `.env.example` para `.env`. Este arquivo **não deve** ser enviado para o Git.

```bash
cp .env.example .env
```

Agora, edite o arquivo `.env` com as suas configurações locais, especialmente as credenciais do banco de dados e as chaves da API do Mercado Livre.

```dotenv
# URL da sua aplicação local
APP_URL=http://localhost:8000

# Credenciais do Banco de Dados
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=meli360
DB_USERNAME=root
DB_PASSWORD=sua_senha_aqui

# Chaves da API do Mercado Livre
ML_APP_ID=seu_app_id
ML_SECRET_KEY=sua_secret_key
ML_REDIRECT_URI=${APP_URL}/ml/callback

# Chave para criptografia dos tokens
ENCRYPTION_KEY=gere_uma_chave_segura
```

**Importante:** Para gerar uma `ENCRYPTION_KEY` segura, você pode usar o comando fornecido pelo pacote `defuse/php-encryption`:

```bash
vendor/bin/generate-defuse-key
```

Copie a chave gerada e cole no seu arquivo `.env`.

### 5. Configure o Banco de Dados

1.  Crie um banco de dados com o nome que você definiu em `DB_DATABASE` (ex: `meli360`).
2.  Importe a estrutura das tabelas. O SQL para criar as tabelas `saas_users`, `mercadolibre_users`, `anuncios`, etc., pode ser encontrado nos arquivos de migração ou na documentação do projeto.

### 6. Execute a Aplicação

Você pode usar o servidor embutido do PHP para rodar o projeto rapidamente. Navegue até a pasta `public` e inicie o servidor.

```bash
cd public
php -S localhost:8000
```

Acesse `http://localhost:8000` no seu navegador.

## 🔄 Scripts de Cron Job

A sincronização dos anúncios é feita por um script que deve ser executado em segundo plano. Para simular isso em desenvolvimento, você pode executá-lo manualmente no terminal:

```bash
php scripts/sync_listings.php
```

Em um ambiente de produção, você configuraria um Cron Job no seu servidor para executar este script a cada minuto:

```crontab
* * * * * cd /caminho/para/seu/projeto && /usr/bin/php scripts/sync_listings.php >> /dev/null 2>&1
```

## 🏛️ Arquitetura

O projeto segue uma arquitetura MVC-like com um padrão Front Controller.

-   **`public/index.php`**: É o único ponto de entrada para todas as requisições HTTP.
-   **`src/routes.php`**: Mapeia as URLs para os métodos dos Controllers.
-   **`src/Controllers`**: Orquestram a lógica, recebem as requisições, interagem com os Models e carregam as Views.
-   **`src/Models`**: Contêm a lógica de negócio e a interação com o banco de dados e APIs externas.
-   **`src/Views`**: Camada de apresentação (HTML + PHP).
-   **`scripts/`**: Scripts para tarefas agendadas (Cron Jobs).

---

Desenvolvido com ❤️ por Meli 360.
