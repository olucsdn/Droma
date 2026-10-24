# Droma (DromaFinancy) — Sistema de Gestão Financeira Pessoal

> **Droma** é uma plataforma moderna, segura e intuitiva para controle financeiro pessoal, desenvolvida rigorosamente sob a metodologia **Spec-Driven Development (SDD)**. O sistema oferece acompanhamento em tempo real de saldos, fluxo de caixa, cartões de crédito com parcelamentos, metas de economia, relatórios analíticos, consultoria via Inteligência Artificial (Google Gemini) e uma API RESTful completa.

---

## 🚀 Tecnologias e Arquitetura

O projeto foi construído preservando estritamente as tecnologias nativas e leves, sem frameworks pesados ou reescritas desnecessárias:

- **Linguagem Principal:** PHP 8.4+ / PHP 8.5 (compatível e testado)
- **Banco de Dados:** MySQL 8.4 com motor InnoDB e integridade referencial estrita
- **Acesso a Dados:** PDO (*PHP Data Objects*) com *Prepared Statements* em 100% das consultas
- **Frontend:** HTML5 semântico, CSS3 Modern Heritage (*dark luxury theme* com tokens de cor ouro, esmeralda e ardósia) e JavaScript puro (Vanilla JS)
- **Visualização de Dados:** [Chart.js](js/chart.umd.min.js) embutido localmente
- **Inteligência Artificial:** Google Gemini API (`gemini-3.5-flash-lite`) com rate-limiting no MySQL e motor heurístico local de fallback
- **Integração:** API RESTful v1.0 com autenticação via `Bearer Token` e console interativo

---

## 🛡️ Pilares de Segurança

- **Proteção Anti-CSRF:** Tokens criptográficos de 64 caracteres hexadecimais gerados por sessão e validados em tempo constante (`hash_equals`) em todas as mutações via POST/DELETE.
- **Prevenção contra IDOR (*Insecure Direct Object Reference*):** Validação de propriedade de contas bancárias, categorias e cartões de crédito (`Security::validateAccountOwnership`, `Security::validateCategoryOwnership`, `Security::validateCreditCardOwnership`) antes de qualquer persistência.
- **Sanitização contra XSS:** Escape contextual (`Security::escape`) e `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` em todos os outputs de dados de usuário.
- **Controle de Cota de IA:** Proteção contra abusos via tabela `ai_daily_usage` limitando a 3 consultas diárias por usuário.

---

## 📦 Módulos do Sistema

| Módulo | Arquivo Principal | Descrição |
|---|---|---|
| **Painel Geral** | [`index.php`](index.php) | Visão consolidada de saldo acumulado, entradas e saídas do mês, gráficos de pizza/barras e metas ativas. |
| **Calendário Financeiro** | [`calendario.php`](calendario.php) | Visão mensal em grade dos lançamentos por data de vencimento ou competência, com faturas de cartão e status pago/pendente. |
| **Metas Financeiras** | [`metas.php`](metas.php) | Cadastro e evolução de metas de economia com cálculo automático de percentual atingido e barras de progresso. |
| **Investimentos & ROI** | [`investimentos.php`](investimentos.php) | Portfólio de ativos com relação direta entre Aportes (saídas), Rendimentos/Resgates (entradas), Lucro Real e cálculo de ROI %. |
| **Contas Bancárias** | [`contas.php`](contas.php) | Entidade pai ("classe") com saldo individual, transferência atômica, visualização de cartões vinculados e bloqueio de exclusão da única conta ou com filhos. |
| **Transferências** | [`transferir.php`](transferir.php) | Transferência atômica entre contas bancárias com transações PDO seguras (débito e crédito casados). |
| **Categorias** | [`categorias.php`](categorias.php) | Gestão de categorias de receitas e despesas com estatísticas de movimentação financeira. |
| **Cartões de Crédito** | [`cartoes.php`](cartoes.php) | Entidade filha ("subclasse" vinculada 1:1 à conta bancária), limites, faturas e quitação com débito padrão na conta de origem. |
| **Lançamentos Recorrentes** | [`recorrencias.php`](recorrencias.php) | Agendamento de despesas e receitas fixas com ciclo mensal/semanal/anual e avanço automático de datas. |
| **Extrato Completo** | [`extrato.php`](extrato.php) | Filtros avançados (período, tipo, status, conta, categoria, busca textual) e exportação em CSV com BOM UTF-8 para Excel. |
| **Relatórios Analíticos** | [`relatorios.php`](relatorios.php) | Fluxo de caixa de 12 meses, gráficos de evolução anual, composição de contas, exportação CSV e visualização para impressão. |
| **Assistente IA** | [`assistente.php`](assistente.php) | Pareceres financeiros personalizados via Google Gemini e diagnósticos heurísticos locais. |
| **Perfil & Chaves de API** | [`perfil.php`](perfil.php) | Atualização de dados pessoais, troca segura de senha e gerenciamento da chave de API REST. |
| **Importação de Extratos** | [`importar.php`](importar.php) | Leitura inteligente de extratos bancários (.CSV / .OFX) com inferência automática de categorias. |
| **Backup & Soberania** | [`backup.php`](backup.php) | Exportação e restauração completa de dados em JSON estruturado com integridade relacional. |
| **API RESTful v1.0** | [`api/v1/index.php`](api/v1/index.php) | Console de testes e endpoints JSON (`/api/v1/resumo.php`, `/api/v1/contas.php`, `/api/v1/categorias.php`, `/api/v1/transacoes.php`). |

---

## 🧪 Suíte de Testes Automatizados (SDD)

O projeto conta com **74 testes de integração automatizados** cobrindo todas as 7 fases de desenvolvimento:

```bash
# Executar suíte completa de testes
php tests/test_suite_phase1.php
php tests/test_suite_phase2.php
php tests/test_suite_phase3.php
php tests/test_suite_phase4.php
php tests/test_suite_phase5.php
php tests/test_suite_phase6.php
php tests/test_suite_phase7.php
```

### Resultados dos Testes:
- **Fase 1 (Estabilização & Segurança):** 12/12 aprovados
- **Fase 2 (Núcleo Financeiro & Extrato):** 9/9 aprovados
- **Fase 3 (Cartões de Crédito & Recorrências):** 6/6 aprovados
- **Fase 4 (Relatórios & Assistente IA):** 8/8 aprovados
- **Fase 5 (API RESTful & Proteção IDOR):** 12/12 aprovados
- **Fase 6 (PWA, Importação OFX/CSV & Backup):** 12/12 aprovados
- **Fase 7 (Investimentos & Hierarquia Conta-Cartão):** 15/15 aprovados
- **Total:** **74/74 testes passando (100% de sucesso)**

---

## ⚙️ Como Executar o Projeto Localmente

### Opção A: Ambiente Tradicional (Laragon / XAMPP)

1. **Ambiente Recomendado:** Laragon (Windows) ou XAMPP com PHP 8.4+ e MySQL 8.4+.
2. **Banco de Dados:**
   - Criar banco de dados `wert` no MySQL:
     ```sql
     CREATE DATABASE wert CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
     ```
   - Executar os scripts do diretório `database/` e `database/migrations/`:
     ```sql
     -- 001_alter_accounts_default_type.sql
     -- 002_create_credit_cards_and_installments.sql
     -- 003_create_ai_daily_usage.sql
     -- 004_add_api_token_to_users.sql
     ```
3. **Configuração de Segredos (IA Gemini):**
   - Crie o arquivo `C:/laragon/segredos/wert.env` (fora da pasta pública) contendo:
     ```env
     GEMINI_API_KEY=sua_chave_do_google_ai_studio
     GEMINI_MODEL=gemini-3.5-flash-lite
     ```
4. **Acesso:**
   - Acesse via navegador: `http://localhost/Droma` ou `http://droma.test`
   - Realize o cadastro inicial na tela `cadastrar.php` ou acesse `login.php`.

---

### Opção B: Containers com Docker & Docker Compose (Zero Configuração Manual)

Caso queira executar o Droma de forma isolada, portável e reprodutível sem precisar instalar PHP ou MySQL na máquina hospedeira:

1. **Subir os containers:**
   ```bash
   docker compose up -d --build
   ```
   *(O Docker irá construir a imagem PHP 8.4 com Apache, subir o MySQL 8.4 na porta `3307` e importar o esquema inicial automaticamente de `docker/mysql-init/01_schema.sql`)*.

2. **Acesso:**
   - **Aplicação Web:** [http://localhost:8080](http://localhost:8080)
   - **Banco de Dados:** Host `localhost`, Porta `3307`, Usuário `wert_user`, Senha `wert_secret`

Para comandos de manutenção, logs, backups e configurações de produção, consulte o manual em [`docs/deploy/docker.md`](docs/deploy/docker.md).

---

## 📚 Documentação Técnica (SDD)

A documentação viva do projeto está centralizada no diretório `docs/`:
- [`docs/sdd/auditoria.md`](docs/sdd/auditoria.md) — Auditoria técnica inicial e mapeamento de código preexistente.
- [`docs/sdd/especificacao.md`](docs/sdd/especificacao.md) — Requisitos funcionais, regras de negócio e critérios de aceitação.
- [`docs/sdd/arquitetura.md`](docs/sdd/arquitetura.md) — Decisões arquiteturais, modelo de dados e segurança.
- [`docs/sdd/roadmap.md`](docs/sdd/roadmap.md) — Histórico de fases e marcos evolutivos.
- [`docs/sdd/tarefas.md`](docs/sdd/tarefas.md) — Checklist atômico de tarefas de cada fase.
- [`docs/api/endpoints.md`](docs/api/endpoints.md) — Manual de referência da API RESTful v1.0.
