# Mapa Central do Sistema — Droma

Este documento é o **índice mestre do código-fonte** da plataforma de gestão financeira **Droma**. Ele organiza a arquitetura do software em 3 níveis (**Módulos**, **Funcionalidades** e **Componentes Técnicos**) e serve de bússola para agentes de IA e desenvolvedores realizarem manutenções com mínimo consumo de contexto, tokens e tempo.

> [!IMPORTANT]
> **Como utilizar este mapa nas manutenções:**
> 1. Consulte a **Matriz de Localização Rápida** ou o módulo correspondente.
> 2. Identifique o código estável da funcionalidade (ex: `IA-001`, `TX-003`).
> 3. Vá direto aos arquivos, símbolos e tabelas listados.
> 4. Não realize buscas cegas nem leia diretórios inteiros sem necessidade.

---

## 1. Matriz de Localização Rápida (Cheat Sheet)

| Código | Funcionalidade Principal | Arquivo(s) de Entrada | Símbolo / Classe Principal | Tabela(s) MySQL | Suíte de Testes |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **`AUTH-001`** | Cadastro de Usuário | `register.php` | PDO Transaction / Password Hash | `users`, `accounts`, `categories` | `tests/test_suite_phase1.php` |
| **`AUTH-002`** | Login e Sessão Segura | `login.php` | `Security::initSession`, `password_verify` | `users` | `tests/test_suite_phase1.php` |
| **`AUTH-003`** | Manter Conectado (Remember) | `login.php`, `src/Security.php` | `Security::attemptRememberLogin` | `users` (`remember_token`) | `tests/test_suite_phase1.php` |
| **`AUTH-004`** | Recuperação de Senha | `recover.php`, `reset_password.php` | `Mailer::sendPasswordReset` | `users` (`reset_token`) | `tests/test_suite_phase1.php` |
| **`AUTH-005`** | Verificação de E-mail | `verify_email.php`, `src/Mailer.php` | `Mailer::sendEmailVerification` | `users` (`verification_token`) | `tests/test_suite_phase1.php` |
| **`AUTH-006`** | Logout Seguro | `logout.php` | `POST` + CSRF + `Security::clearRememberCookie` | `users` (`remember_token`) | `tests/test_suite_phase1.php` |
| **`AUTH-007`** | Perfil e Troca de Senha | `profile.php` | `Security::validateCsrf` | `users` | `tests/test_suite_phase1.php` |
| **`AUTH-008`** | Rate Limiting e Força Bruta | `src/RateLimiter.php` | `RateLimiter::isBlocked`, `hit` | `rate_limits` | `tests/test_suite_phase1.php` |
| **`AUTH-009`** | Termos de Uso e LGPD | `terms.php`, `accept_terms.php` | `Security::hasAcceptedTerms`, `recordTermsAcceptance` | `users` (`terms_accepted_at`) | `tests/test_suite_phase1.php` |
| **`AUTH-010`** | Política de Senha & Argon2id | `register.php`, `profile.php`, `reset_password.php`, `js/password-meter.js` | `Security::validatePasswordStrength`, `Security::hashPassword`, `Security::revokeAllSessions`, `Mailer::sendSecurityPasswordChanged` | `users` (`password`, `remember_token`) | `tests/test_suite_phase1.php` |
| **`SITE-001`** | Landing Page de Apresentação | `landing.php`, `apresentacao.php`, `style_landing.css` | Showcase visual, FAQs, CTAs de conversão | N/A | `tests/test_suite_phase1.php` |
| **`SITE-002`** | Página de Erro 404 Personalizada | `404.php`, `.htaccess` | Status HTTP 404, fallback JSON RESTful e navegação contextual | N/A | `tests/test_suite_phase1.php` |
| **`DASH-001`** | Painel / Cards de Indicadores | `index.php` | Consultas agregadas SQL | `accounts`, `transactions` | `tests/test_suite_phase2.php` |
| **`DASH-002`** | Gráficos do Dashboard | `index.php` | JS Chart Canvas | `transactions`, `categories` | `tests/test_suite_phase2.php` |
| **`DASH-003`** | Feed de Transações Recentes | `index.php` | Loop de listagem + Forms POST | `transactions` | `tests/test_suite_phase2.php` |
| **`TX-001`** | Lançamento de Receita/Despesa | `add_expense.php`, `save_expense.php` | `Security::validateAccountOwnership` | `transactions`, `installments` | `tests/test_suite_phase2.php` |
| **`TX-002`** | Edição de Transação | `edit_expense.php` | `Security::validateCsrf` | `transactions` | `tests/test_suite_phase2.php` |
| **`TX-003`** | Exclusão Segura (Soft Delete) | `delete_expense.php` | `Security::validateCsrf`, `UPDATE visivel=0` | `transactions`, `installments` | `tests/test_suite_phase2.php` |
| **`TX-004`** | Transferência Entre Contas | `transfer.php` | PDO Transaction (Débito + Crédito) | `transactions`, `accounts` | `tests/test_suite_phase2.php` |
| **`TX-005`** | Extrato com Filtros | `statement.php` | Query Builder dinâmico | `transactions`, `categories`, `accounts` | `tests/test_suite_phase2.php` |
| **`ACC-001`** | Gestão de Contas Bancárias | `accounts.php`, `src/AccountService.php` | CRUD de Contas, `AccountService::saldo` | `accounts`, `transactions` | `tests/test_suite_phase2.php` |
| **`CARD-001`** | Cartões de Crédito e Limites | `cards.php` | `Security::validateCreditCardOwnership` | `credit_cards`, `accounts` | `tests/test_suite_phase3.php` |
| **`CARD-002`** | Compras Parceladas | `save_expense.php`, `cards.php` | Loop gerador de parcelas | `installments`, `transactions` | `tests/test_suite_phase3.php` |
| **`CARD-003`** | Fatura e Liquidação | `cards.php` | Cálculo de ciclo de corte/vencimento | `installments`, `credit_cards` | `tests/test_suite_phase3.php` |
| **`CAT-001`** | Categorias & Object Pool | `categories.php`, `src/CategoryService.php` | `CategoryService::criarOuReciclar`, `excluir` | `categories`, `transactions` | `tests/test_suite_phase2.php` |
| **`CAT-002`** | Tetos Orçamentários (Budget) | `categories.php`, `reports.php` | Comparativo Gasto vs Limite | `categories` (`budget_limit`) | `tests/test_suite_phase2.php` |
| **`CAL-001`** | Calendário de Vencimentos | `calendar.php` | Agrupamento de eventos por dia | `transactions` (`due_date`) | `tests/test_suite_phase2.php` |
| **`CAL-002`** | Urgência e Liquidação Rápida | `calendar.php` | Update de status via POST | `transactions` (`status`, `urgency`) | `tests/test_suite_phase2.php` |
| **`REC-001`** | Recorrências e Assinaturas | `recurring.php` | Agendador de frequência | `recurring_transactions` | `tests/test_suite_phase3.php` |
| **`GOAL-001`** | Metas de Economia | `goals.php` | CRUD e Aporte/Resgate | `goals` | `tests/test_suite_phase3.php` |
| **`INV-001`** | Carteira de Investimentos | `investments.php` | `Security::validateInvestmentOwnership` | `investments`, `accounts` | `tests/test_suite_phase7.php` |
| **`INV-002`** | Integração Aporte-Conta | `investments.php` | PDO Transaction (Investimento + Tx) | `investments`, `transactions` | `tests/test_suite_phase7.php` |
| **`REP-001`** | Relatórios Analíticos | `reports.php` | Agrupamentos analíticos e KPIs | `transactions`, `categories` | `tests/test_suite_phase4.php` |
| **`IA-001`** | Análise Financeira com IA | `assistant.php`, `src/ClienteIA.php` | `ClienteIA::analisar`, `analisarGemini` | `ai_daily_usage` | `tests/test_suite_phase4.php` |
| **`IA-002`** | Contingência Multiprovedor | `src/ClienteIA.php` | Fallback Hugging Face e Motor Local | N/A | `tests/test_suite_phase4.php` |
| **`IA-003`** | Controle de Cota Quinzenal | `src/ClienteIA.php` | `ClienteIA::getUsage`, `recordUsage` | `ai_daily_usage` | `tests/test_suite_phase4.php` |
| **`DATA-001`**| Importação OFX / CSV | `import.php`, `src/ExtratoParser.php` | `ExtratoParser::parseOfx`, `parseCsv`, `parseCsvStream`, `Security::ensureImportHashColumn` | `transactions` (`import_hash`), `accounts` | `tests/test_suite_phase6.php`, `tests/test_suite_security_oom.php` |
| **`DATA-002`**| Backup Geral (JSON/CSV) | `backup.php` | Exportador streaming e restauração atômica | Todas as tabelas do usuário | `tests/test_suite_phase6.php`, `tests/test_suite_backup_security.php`, `tests/test_suite_security_oom.php` |
| **`API-001`** | Console e Autenticação REST | `api/v1/index.php`, `src/ApiAuth.php` | `ApiAuth::authenticate` (Bearer Token via `api_token_hash`) | `users` (`api_token_hash`) | `tests/test_suite_phase5.php` |
| **`API-002`** | Endpoints REST v1 | `api/v1/*.php`, `src/ApiResponse.php` | Handlers JSON RESTful (`accounts`, `categories`, `summary`, `transactions` + aliases PT) | `transactions`, `accounts`, etc. | `tests/test_suite_phase5.php`, `tests/test_suite_security_oom.php` |
| **`API-003`** | Sincronização Delta (Offline) | `api/v1/sync.php` | Sincronizador bidirecional com idempotência UNIQUE `(user_id, client_id)` | `transactions` | `tests/test_suite_phase5.php` |
| **`PWA-001`** | Operação Offline e Push | `sw.js`, `manifest.json`, `offline.html`, `js/push-notifications.js`, `actions/due_alerts.php` | Network-Only HTML / Cache estático + Web Push & alertas de vencimento | Cache API Browser (`droma-cache-v20`) | `tests/test_suite_phase6.php` |
| **`NOTIF-001`** | Lembretes, Recorrências e Orçamento | `cron/notify_bills.php`, `src/Mailer.php`, `index.php` | `Mailer::sendBillDueReminder`, recorrências automáticas, alerta 80%/100% | `transactions`, `installments`, `recurring_transactions`, `categories`, `daily_notifications` | `tests/test_suite_notif.php` |
| **`PLAN-001`** | Projeção, Patrimônio, Tags & Texto | `src/PlanningService.php`, `index.php`, `add_expense.php`, `save_expense.php` | `PlanningService::saldoProjetado`, `patrimonioLiquido`, `salvarTagsESplits`, `parseTextoLancamento` | `tags`, `transaction_tags`, `transaction_splits`, `net_worth_snapshots` | N/A |
| **`GOV-001`** | Governança IA & Economia Extrema | `AGENTS.md`, `HANDOFF.md`, `docs/MANUAL_*.md` | Framework Coringa v4.0-Universal / Checkpoint `[STATE]` | N/A | N/A |

---

## 2. Componentes Transversais e Compartilhados

Antes de qualquer manutenção em telas ou controllers, note os componentes que protegem e estruturam toda a aplicação:

- **Conexão PDO Segura ([`config/database.php`](../config/database.php)):**
  Fornece a instância global `$pdo`. Trata automaticamente charset UTF-8 completo (`utf8mb4`), exceções em modo `ERRMODE_EXCEPTION`, variáveis de ambiente locais ou de nuvem e certificados SSL/TLS para conexões remotas.
- **Segurança Centralizada ([`src/Security.php`](../src/Security.php)):**
  - Autenticação e Sessão: `Security::requireAuth()` (redireciona para `landing`, ou retorna HTTP `401` JSON em AJAX `XMLHttpRequest`), `Security::initSession()`.
  - Proteção CSRF e Anti-Reenvio: `Security::csrfToken()`, `Security::csrfField()` (com `form_nonce` de uso único), `Security::validateCsrf()`, `Security::claimFormNonce()`.
  - Validação de Propriedade (Multi-tenant): `Security::validateAccountOwnership()`, `Security::validateCategoryOwnership()`, `Security::validateCreditCardOwnership()`, `Security::validateInvestmentOwnership()`.
  - Remember Me: `Security::attemptRememberLogin()`, `Security::setRememberCookie()`.
  - Sanitização e Endurecimento: `Security::escape()`, `Security::sanitizeCsvCell()` (sanitização de fórmulas CSV com `ltrim` contra injeção maliciosa DDE / CWE-1236 em `statement.php` e `reports.php`), `Security::applySecurityHeaders()` (cabeçalhos de segurança HTTP e `Cache-Control: no-store` defensivo anti-exposição em cache / CWE-525).
- **Rate Limiting & Topologia de Rede ([`src/RateLimiter.php`](../src/RateLimiter.php)):**
  - Resolução de IP seguro com varredura Right-to-Left na cadeia `X-Forwarded-For` parando no primeiro nó não-proxy.
  - Suporte completo a CIDR IPv4 e IPv6 via casamento binário `inet_pton`.
  - Em produção (Render/VPS), recomenda-se configurar explicitamente a variável `TRUSTED_PROXIES` com os IPs/CIDRs dos balanceadores para desativar qualquer fallback de sub-rede ampla e garantir imunidade total contra IP spoofing.
- **Layouts Globais:**
  - Cabeçalho, navegação lateral e topbar: [`includes/layout_header.php`](../includes/layout_header.php).
  - Fechamento de tags, scripts e registro PWA: [`includes/layout_footer.php`](../includes/layout_footer.php).
  - Favicons responsivos e tema escuro/claro: [`includes/head_favicons.php`](../includes/head_favicons.php).
- **Roteamento e Organização em Pastas ([`router.php`](../router.php), [`.htaccess`](../.htaccess)):**
  - `index.php` permanece na raiz como dashboard/entrada; todas as demais rotas amigáveis são despachadas por `router.php` (`chdir(__DIR__)` preserva os caminhos relativos):
  - `pages/auth/` (autenticação, perfil, termos e verificação), `pages/finance/` (telas financeiras), `pages/public/` (`landing.php`, `apresentacao.php`, `404.php`), `actions/` (`save_expense.php`, `delete_expense.php`, `restore_expense.php`), `assets/css/` (`tokens.css`, `components.css`, `style_*.css`) e redirects 301 em português direto no `router.php`.

---

## 3. Módulos Detalhados do Sistema

---

### MÓDULO 1: AUTH — Autenticação, Usuários e Segurança de Acesso

#### `AUTH-001` — Cadastro de Usuário
- **Objetivo:** Registro de novos usuários com criação atômica de conta bancária inicial e categorias padrão.
- **Arquivos:** [`register.php`](../register.php) (canônico), [`cadastrar.php`](../cadastrar.php) (redirect), [`registro.php`](../registro.php) (redirect).
- **Entrada:** `POST` com `name`, `email`, `password`, `confirm_password`.
- **Tabelas:** `users`, `accounts`, `categories`.
- **Regras:** Senha com `password_hash(PASSWORD_DEFAULT)`; criação de conta "Carteira Principal" (`checking`) e 9 categorias essenciais (6 despesas, 3 receitas) em transação atômica; normalização de e-mail em minúsculas com `Security::normalizeEmail()`, verificação atômica anti-duplicação insensível a maiúsculas/espaços (`LOWER(TRIM(email)) = ?`) com bloqueio `FOR UPDATE`, garantia estrutural de índice `UNIQUE` na tabela `users` via `Security::ensureNormalizedEmails()`, captura de violação de unicidade (SQLSTATE 23000 / Error 1062) com mensagem explicativa amigável e proteção front-end contra múltiplos envios por duplo clique.
- **Validação:** `tests/test_suite_phase1.php`.

#### `AUTH-002` — Login e Autenticação de Sessão
- **Objetivo:** Autenticação de credenciais, regeneração segura de sessão e roteamento inteligente.
- **Arquivos:** [`login.php`](../login.php), [`paginalogar.php`](../paginalogar.php) (redirect).
- **Entrada:** `POST` com `email`, `senha`, `remember` (opcional).
- **Tabelas:** `users`.
- **Regras:** Usuários autenticados que acessam `login.php` ou `register.php` são encaminhados ao dashboard (`./`); visitantes não autenticados que acessam rotas protegidas ou a raiz são redirecionados para a landing page (`landing`); `session_regenerate_id(true)` no login; busca de usuário insensível a maiúsculas com `LOWER(email) = ?` e auto-migração de e-mails legados via `Security::ensureNormalizedEmails()`; mensagens genéricas para evitar enumeração de usuários.
- **Validação:** `tests/test_suite_phase1.php`.

#### `AUTH-003` — Manter Conectado (Remember-Me)
- **Objetivo:** Persistência segura de login via cookie de longo prazo.
- **Arquivos:** [`src/Security.php`](../src/Security.php), [`login.php`](../login.php).
- **Métodos:** `Security::setRememberCookie()`, `Security::attemptRememberLogin()`, `Security::clearRememberCookie()`.
- **Tabelas:** `users` (`remember_token`).
- **Regras:** Token aleatório de 64 caracteres em hexadecimal; cookie `HttpOnly` e `SameSite=Lax`.

#### `AUTH-004` — Recuperação e Redefinição de Senha
- **Objetivo:** Envio de e-mail com token temporário e formulário de redefinição de senha.
- **Arquivos:** [`recover.php`](../recover.php), [`reset_password.php`](../reset_password.php), [`src/Mailer.php`](../src/Mailer.php).
- **Tabelas:** `users` (`reset_token`, `reset_expires_at`).
- **Regras:** Expiração do token em 1 hora; invalidação imediata após uso; busca de usuário normalizada e insensível a maiúsculas (`LOWER(email) = ?`).

#### `AUTH-005` — Verificação de E-mail
- **Objetivo:** Confirmação da autenticidade da caixa postal do usuário.
- **Arquivos:** [`verify_email.php`](../verify_email.php), [`src/Mailer.php`](../src/Mailer.php).
- **Tabelas:** `users` (`verification_token`, `email_verified_at`).
- **Regras:** Validação estritamente via formulário POST com preenchimento manual do código de 6 dígitos pelo usuário (proteção contra bypass via URL e exposição em query string); busca de usuário insensível a maiúsculas (`LOWER(email) = ?`); proteção contra enumeração de contas e timing attack através de resposta padronizada uniforme, código HTTP homogêneo e comparação de tempo constante (`hash_equals`).

#### `AUTH-006` — Logout Seguro
- **Objetivo:** Encerramento completo de sessão, limpeza de cookies, purga de armazenamento offline local e redirecionamento para a landing page.
- **Arquivos:** [`logout.php`](../logout.php), [`includes/layout_header.php`](../includes/layout_header.php), [`includes/layout_footer.php`](../includes/layout_footer.php), [`js/app.js`](../js/app.js), [`landing.php`](../landing.php).
- **Regras:** Confirmação modal preventiva (`DromaConfirm`); destrói dados em memória, expira cookie de sessão, chama `Security::clearRememberCookie()`, dispara cabeçalho W3C `Clear-Site-Data: "storage"` e elimina atomicamente `droma_cached_meta`, `droma_fallback_pending` e banco `DromaOfflineDB` do `localStorage`/`IndexedDB` do navegador, redirecionando para a landing page (`landing`).

#### `AUTH-007` — Gestão de Perfil do Usuário
- **Objetivo:** Alteração de dados cadastrais, e-mail e senha.
- **Arquivos:** [`profile.php`](../profile.php), [`perfil.php`](../perfil.php) (redirect).
- **Tabelas:** `users`.
- **Regras:** Alteração de senha ou e-mail exige confirmação prévia da senha atual; validação estrita de CSRF.

#### `AUTH-008` — Rate Limiting e Prevenção de Força Bruta
- **Objetivo:** Bloqueio de IP após tentativas repetidas de login ou recuperação de senha.
- **Arquivos:** [`src/RateLimiter.php`](../src/RateLimiter.php).
- **Tabelas:** `rate_limits`.
- **Métodos:** `RateLimiter::isBlocked()`, `RateLimiter::hit()`, `RateLimiter::clear()`.

#### `AUTH-009` — Termos de Uso e Conformidade LGPD
- **Objetivo:** Garantir conformidade jurídica, isenção de responsabilidade regulatória (CVM/Bacen) e direitos do titular de dados (LGPD - Lei 13.709/2018).
- **Arquivos:** [`terms.php`](../terms.php), [`termos.php`](../termos.php) (redirect), [`accept_terms.php`](../accept_terms.php), [`register.php`](../register.php).
- **Tabelas:** `users` (`terms_accepted_at`).
- **Métodos:** `Security::ensureTermsColumn()`, `Security::hasAcceptedTerms()`, `Security::recordTermsAcceptance()`.
- **Regras:** Aceite obrigatório no cadastro para novos usuários e modal bloqueante no dashboard para usuários existentes até confirmação.

#### `AUTH-010` — Política de Senha, Medidor Visual e Argon2id
- **Objetivo:** Garantir a máxima proteção contra vazamentos, interceptações, ataques de dicionário e acessos não autorizados sem atrito ao usuário.
- **Arquivos:** [`src/Security.php`](../src/Security.php), [`src/Mailer.php`](../src/Mailer.php), [`register.php`](../register.php), [`profile.php`](../profile.php), [`reset_password.php`](../reset_password.php), [`login.php`](../login.php), [`js/password-meter.js`](../js/password-meter.js), [`style_login.css`](../style_login.css), [`style_form.css`](../style_form.css).
- **Tabelas:** `users` (`password`, `remember_token`).
- **Métodos:** `Security::validatePasswordStrength()`, `Security::hashPassword()`, `Security::getPasswordAlgo()`, `Security::revokeAllSessions()`, `Mailer::sendSecurityPasswordChanged()`.
- **Regras:** Senhas com no mínimo 8 caracteres contendo maiúsculas, minúsculas e números; bloqueio de senhas comuns e com nome/e-mail; criptografia com Argon2id (ou Bcrypt custo 12) e migração transparente no login (`password_needs_rehash`); feedback visual em tempo real no front-end; revogação de sessões antigas e disparo de alerta por e-mail em qualquer troca de senha.
- **Validação:** `tests/test_suite_phase1.php`.

#### `SITE-001` — Landing Page de Apresentação (Página Inicial Pública)
- **Objetivo:** Apresentação visual da plataforma Droma para novos visitantes e porta de entrada padrão quando não autenticado.
- **Arquivos:** [`landing.php`](../landing.php), [`apresentacao.php`](../apresentacao.php) (alias), [`style_landing.css`](../style_landing.css), [`simulacao_paleta.html`](../simulacao_paleta.html).
- **Recursos:** Navbar com ancoragem suave e CTAs de cadastro/login, hero editorial responsivo com prévia do painel, grade assimétrica de funcionalidades, seção de segurança/privacidade, FAQ acessível por teclado com estado `aria-expanded` sincronizado, rodapé institucional e limpeza de metadados residuais no navegador.
- **Regras:** Se o visitante já possuir sessão ativa, a navbar exibe "Acessar Meu Painel →" direcionando ao dashboard (`./`). Visitantes sem sessão que acessam a aplicação (`index.php` ou rotas protegidas) são automaticamente redirecionados para a landing page.

#### `SITE-002` — Página de Erro 404 Personalizada (Not Found)
- **Objetivo:** Tratamento elegante e seguro para rotas inexistentes, URLs digitadas incorretamente e chamadas de API quebradas.
- **Arquivos:** [`404.php`](../404.php), [`.htaccess`](../.htaccess).
- **Recursos:** Status HTTP 404 garantido (`http_response_code(404)`), layout responsivo no padrão Droma Luxury Dark com dígitos em gradiente metálico, detecção automática de requisições de API/AJAX retornando JSON estruturado (`{"status":"error","code":404,...}`), sanitização contra XSS de rota solicitada, botões inteligentes adaptados ao estado de sessão do usuário (painel e extrato para logados; apresentação e login para visitantes) e botão de retorno dinâmico ao histórico.
- **Regras:** Configurado via diretiva `ErrorDocument 404 /404.php` e fallback no `mod_rewrite` do Apache no `.htaccess`; bloqueio estrito de acesso direto HTTP a diretórios internos (`database`, `docker`, `docs`, `scratch`, `tests`, `config`, `src`, `includes`, `.agents`) com status `403 Forbidden`; restrição a extensões e scripts sensíveis (`.env`, `.sql`, `.log`, `.md`, `.ini`, `.yml`, `.lock`, `.sh`, `Dockerfile`).

---

### MÓDULO 2: DASH — Dashboard Financeiro Central

#### `DASH-001` — Consolidação de Indicadores (Cards de Saldo)
- **Objetivo:** Exibição dos cards de Saldo Atual, Receitas do Mês, Despesas do Mês e Previsão Fim do Mês em grade balanceada.
- **Arquivos:** [`index.php`](../index.php), [`style_index.css`](../style_index.css).
- **Tabelas:** `accounts`, `transactions`.
- **Regras:** Saldo Total = soma de `accounts.initial_balance` + transações `income` realizadas - transações `expense` realizadas. Previsão Fim do Mês = `Saldo Total` + receitas pendentes do mês - despesas/parcelas pendentes do mês (sem dupla contagem com movimentações já realizadas no mês, alinhado à lógica de `calendar.php`). Grid desktop bloqueado em 2x2.

#### `DASH-002` — Gráficos do Dashboard
- **Objetivo:** Visualização gráfica de fluxo de caixa mensal e distribuição de gastos por categoria.
- **Arquivos:** [`index.php`](../index.php).
- **Tecnologia:** Canvas nativo / Chart.js sem build externa.

#### `DASH-003` — Feed de Últimas Transações
- **Objetivo:** Listagem das últimas movimentações restritas ao mês corrente (`MONTH(t.date) = MONTH(CURDATE()) AND YEAR(t.date) = YEAR(CURDATE())`) com badge de urgência e botão de exclusão rápida via formulário POST protegido por CSRF.
- **Arquivos:** [`index.php`](../index.php), [`delete_expense.php`](../delete_expense.php).
- **Tabelas:** `transactions`, `categories`, `accounts`.

---

### MÓDULO 3: TX — Gestão de Transações e Movimentações

#### `TX-001` — Lançamento de Receitas e Despesas
- **Objetivo:** Criação de transações à vista ou vinculadas a cartão de crédito.
- **Arquivos:** [`add_expense.php`](../add_expense.php), [`save_expense.php`](../save_expense.php), [`adicionar_gasto.php`](../adicionar_gasto.php) (redirect), [`js/app.js`](../js/app.js).
- **Tabelas:** `transactions`, `installments`, `accounts`, `categories`.
- **Regras:** Validação obrigatória com `Security::validateAccountOwnership()` e `Security::validateCategoryOwnership()`. Proteção global contra double-submit no front-end, tratamento gracioso de erros com redirecionamento contextual (`?erro=...` / JSON 422 sem telas brancas `die()`), snapshot imutável em `category_name` e `visivel = 1`. Se houver parcelamento, aciona rotina de `installments`.
- **Validação:** `tests/test_suite_phase2.php`.

#### `TX-002` — Edição de Transações
- **Objetivo:** Modificação de valores, descrição, vencimento, categoria ou conta de uma transação.
- **Arquivos:** [`edit_expense.php`](../edit_expense.php), [`editar_gasto.php`](../editar_gasto.php) (redirect).
- **Tabelas:** `transactions`.
- **Regras:** Validação multi-tenant (`user_id = ?`) e token CSRF. Transações originadas de transferências entre contas (`transfer_id`) ou metas (`goal_id`) são protegidas contra edição direta com redirecionamento contextual para manter o equilíbrio contábil.

#### `TX-003` — Exclusão Segura via POST (Soft Delete)
- **Objetivo:** Remoção lógica de transação sem permitir exclusão via link GET e sem comando DELETE físico no banco.
- **Arquivos:** [`delete_expense.php`](../delete_expense.php), [`excluir_gasto.php`](../excluir_gasto.php) (redirect).
- **Tabelas:** `transactions`, `installments`, `goals`.
- **Regras:** Rejeita requisições GET com HTTP 405/redirecionamento; exige token CSRF válido e titularidade do usuário; executa Soft Delete alterando `visivel = 0` para preservar auditoria e conciliação. Cancela atomicamente ambas as pernas de transferências (`transfer_id`), estorna saldos de metas financeiras (`goal_id`) e cancela parcelas pendentes de compras parceladas no cartão (`installments`).
- **Validação:** `tests/test_suite_phase2.php`.

#### `TX-004` — Transferência Entre Contas
- **Objetivo:** Movimentação entre duas contas bancárias do usuário.
- **Arquivos:** [`transfer.php`](../transfer.php), [`transferir.php`](../transferir.php) (redirect).
- **Tabelas:** `transactions` (`transfer_id`), `accounts`.
- **Regras:** Validação obrigatória de saldo suficiente na conta de origem recalculada atomicamente sob pessimistic locking (`FOR UPDATE`) dentro da transação após confirmar `rowCount() === 2`, eliminando condições de corrida concorrentes e impedindo que a conta fique negativada. Executada em transação atômica (`beginTransaction` / `commit`), gerando uma despesa na conta de origem e uma receita na conta de destino vinculadas pelo identificador único `transfer_id`. Excluída dos cálculos de receitas e despesas externas no dashboard, relatórios e tela de contas, mantendo os saldos de cada conta independentes e exatos. Exclusão atômica de ambas as pernas via `transfer_id`.

#### `TX-005` — Extrato Detalhado
- **Objetivo:** Visão analítica de todas as transações com filtros por período, tipo, conta, categoria e status.
- **Arquivos:** [`statement.php`](../statement.php), [`extrato.php`](../extrato.php) (redirect).
- **Tabelas:** `transactions`, `accounts`, `categories`.

---

### MÓDULO 4: ACC — Contas Bancárias e Carteiras

#### `ACC-001` — Gestão de Contas Bancárias
- **Objetivo:** Cadastro, edição e listagem de contas de diferentes tipos (`checking`, `savings`, `cash`, `investment`).
- **Arquivos:** [`accounts.php`](../accounts.php), [`contas.php`](../contas.php) (redirect).
- **Tabelas:** `accounts`.
- **Regras:** A conta inicial padrão criada no registro possui tipo `checking`. Saldo inicial armazenado em `initial_balance`. O saldo atual reflete `initial_balance + total_receitas - total_despesas + saldo_transferencias`, garantindo que transferências internas não inflem os cards globais de "Total em Entradas" e "Total em Saídas". Subqueries independentes eliminam multiplicação cartesiana de cartões de crédito vinculados.

---

### MÓDULO 5: CARD — Cartões de Crédito e Faturas

#### `CARD-001` — Gestão de Cartões de Crédito
- **Objetivo:** Configuração de cartões, limites de crédito, dia de fechamento e dia de vencimento.
- **Arquivos:** [`cards.php`](../cards.php), [`cartoes.php`](../cartoes.php) (redirect).
- **Tabelas:** `credit_cards`, `accounts`.
- **Regras:** Cada cartão de crédito possui vínculo com uma conta bancária (`account_id`).

#### `CARD-002` — Parcelamentos e Faturas
- **Objetivo:** Divisão automática de compras parceladas em lançamentos futuros vinculados ao cartão.
- **Arquivos:** [`save_expense.php`](../save_expense.php), [`cards.php`](../cards.php).
- **Tabelas:** `installments`, `transactions`, `credit_cards`.
- **Regras:** Vencimento das parcelas calculado a partir do dia de fechamento (`closing_day`) e dia de vencimento (`due_day`).

#### `CARD-003` — Pagamento e Liquidação de Fatura
- **Objetivo:** Quitação consolidada da fatura do cartão com débito na conta bancária correspondente.
- **Arquivos:** [`cards.php`](../cards.php).
- **Tabelas:** `installments`, `transactions`, `accounts`, `categories`.
- **Regras:** Executado em transação atômica (`beginTransaction` / `commit`) com pessimistic locking (`FOR UPDATE`) nas parcelas e lançamentos pendentes, eliminando race conditions de débitos concorrentes duplicados. Grava snapshot de categoria e `visivel = 1`. A baixa de fatura debita a conta bancária como desembolso consolidado; as consultas de saldo de conta bancária filtram `credit_card_id IS NULL`, impedindo dupla contagem com os lançamentos individuais de cartão. No soft delete (`delete_expense.php`), parcelas de compras canceladas são desativadas atomicamente.

---

### MÓDULO 6: CAT — Categorias e Tetos Orçamentários

#### `CAT-001` — Categorias Financeiras com Object Pool & Soft Delete
- **Objetivo:** Gestão de categorias de receita (`income`, máx 3) e despesa (`expense`, máx 8) com pool fechado de 11 slots por usuário, exclusão lógica e reciclagem inteligente de registros.
- **Arquivos:** [`categories.php`](../categories.php), [`categorias.php`](../categorias.php) (redirect), [`src/CategoryService.php`](../src/CategoryService.php).
- **Tabelas:** `categories`, `transactions`.
- **Regras:** Limite de 8 despesas e 3 receitas ativas. Exclusão nunca executa `DELETE`, apenas `visivel = 0`. Criação prioriza reativação ou reciclagem de slots inativos antes de novos `INSERT`s para manter o `AUTO_INCREMENT` e tamanho da tabela estáveis. Preserva a integridade histórica de transações antigas via snapshot imutável em `transactions.category_name` e desvinculação em reciclagem.
- **Validação:** `tests/test_suite_phase2.php`.

#### `CAT-002` — Tetos Orçamentários (Budget Limits)
- **Objetivo:** Definição de limites de gastos mensais por categoria com alertas visuais de consumo.
- **Arquivos:** [`categories.php`](../categories.php), [`reports.php`](../reports.php).
- **Tabelas:** `categories` (`budget_limit`), `transactions`.
- **Regras:** Alerta visual quando o consumo do mês ultrapassa 80% e 100% do teto.

---

### MÓDULO 7: CAL — Calendário Financeiro e Vencimentos

#### `CAL-001` — Calendário Mensal de Vencimentos
- **Objetivo:** Visão visual em grade do mês identificando contas a pagar, a receber e vencidas.
- **Arquivos:** [`calendar.php`](../calendar.php), [`calendario.php`](../calendario.php) (redirect).
- **Tabelas:** `transactions` (`due_date`, `status`).

#### `CAL-002` — Níveis de Urgência e Liquidação Rápida
- **Objetivo:** Classificação de despesas por urgência (`baixa`, `media`, `alta`) e botão de marcação de pagamento.
- **Arquivos:** [`calendar.php`](../calendar.php).
- **Tabelas:** `transactions` (`urgency`, `status`).

---

### MÓDULO 8: REC — Recorrências e Assinaturas

#### `REC-001` — Lançamentos Recorrentes
- **Objetivo:** Configuração de receitas ou despesas programadas com frequência semanal, mensal ou anual.
- **Arquivos:** [`recurring.php`](../recurring.php), [`recorrencias.php`](../recorrencias.php) (redirect).
- **Tabelas:** `recurring_transactions`, `accounts`, `categories`.

#### `REC-002` — Processamento de Recorrências
- **Objetivo:** Geração efetiva de transações em `transactions` a partir da regra programada.
- **Arquivos:** [`recurring.php`](../recurring.php).

---

### MÓDULO 9: GOAL — Metas de Economia

#### `GOAL-001` — Metas Financeiras (Caixinhas de Economia)
- **Objetivo:** Criação de objetivos de economia com valor alvo, prazo limite e opção de aporte inicial rastreado.
- **Arquivos:** [`goals.php`](../goals.php), [`metas.php`](../metas.php) (redirect).
- **Tabelas:** `goals`, `accounts`, `transactions` (`goal_id`).
- **Símbolos:** `Security::ensureGoalColumn()`, `Security::validateGoalOwnership()`.

#### `GOAL-002` — Aportes e Resgates Rastreados (Modelo Caixinhas)
- **Objetivo:** Aportes com débito real na conta de origem e resgates com crédito na conta de destino, atualizando atomicamente `current_amount` e gerando transações bancárias no extrato.
- **Arquivos:** [`goals.php`](../goals.php).
- **Tabelas:** `goals`, `transactions`, `accounts`, `categories`.
- **Regras:** Validação de saldo suficiente na conta de origem para aportes e na meta para resgates; transação atômica PDO com controle de concorrência.

---

### MÓDULO 10: INV — Carteira de Investimentos

#### `INV-001` — Gestão de Ativos Financeiros
- **Objetivo:** Controle de patrimônio investido por tipo: Renda Fixa, Ações, FIIs, Cripto, Fundos e Outros.
- **Arquivos:** [`investments.php`](../investments.php), [`investimentos.php`](../investimentos.php) (redirect).
- **Tabelas:** `investments`, `accounts`.
- **Símbolos:** `Security::validateInvestmentOwnership()`.
- **Validação:** `tests/test_suite_phase7.php`.

#### `INV-002` — Integração com Débito em Conta
- **Objetivo:** Registro do aporte financeiro com débito opcional na conta bancária vinculada.
- **Arquivos:** [`investments.php`](../investments.php).
- **Tabelas:** `investments`, `transactions`, `accounts`.

---

### MÓDULO 11: REP — Relatórios e Análises

#### `REP-001` — Relatórios Analíticos Mensais e Anuais
- **Objetivo:** Demonstração financeira completa de receitas versus despesas, taxa de poupança e maiores categorias.
- **Arquivos:** [`reports.php`](../reports.php), [`relatorios.php`](../relatorios.php) (redirect).
- **Tabelas:** `transactions`, `categories`, `accounts`.

#### `REP-002` — Relatório de Aderência Orçamentária
- **Objetivo:** Gráficos e indicadores comparativos entre o limite orçamentário configurado e o realizado no período.
- **Arquivos:** [`reports.php`](../reports.php).

---

### MÓDULO 12: IA — Assistente Financeiro Inteligente

#### `IA-001` — Consultoria Financeira com IA
- **Objetivo:** Geração de diagnóstico financeiro e exatamente 3 orientações práticas com tom acolhedor baseadas nos números consolidados do usuário.
- **Arquivos:** [`assistant.php`](../assistant.php), [`assistente.php`](../assistente.php) (redirect), [`src/ClienteIA.php`](../src/ClienteIA.php).
- **Provedor Principal:** Google Gemini (`gemini-3.6-flash`, fallback para `gemini-3.5-flash`).
- **Símbolos:** `ClienteIA::analisar()`, `ClienteIA::analisarGemini()`.
- **Tabelas:** `ai_daily_usage`.
- **Validação:** `tests/test_suite_phase4.php`.

#### `IA-002` — Arquitetura de Tolerância a Falhas e Contingência Multiprovedor
- **Objetivo:** Garantir disponibilidade ininterrupta sem telas de erro caso uma API externa falhe.
- **Arquivos:** [`src/ClienteIA.php`](../src/ClienteIA.php).
- **Segurança de Credenciais:** Descoberta de chaves via `obterEnv()` restrita estritamente a variáveis de ambiente (`getenv`, `$_ENV`) e ao arquivo `.env` na raiz do projeto (mitigação CWE-200 / zero caminhos de disco expostos).
- **Hierarquia:**
  1. Google Gemini (`gemini-3.6-flash` / `gemini-3.5-flash`);
  2. Hugging Face Router (`meta-llama/Llama-3.1-8B-Instruct`);
  3. Droma Engine Local (`ClienteIA::gerarAnaliseLocal()` — diagnóstico financeiro determinístico offline).
- **Símbolos:** `ClienteIA::analisarHuggingFace()`, `ClienteIA::gerarAnaliseLocal()`.

#### `IA-003` — Controle de Cota Quinzenal
- **Objetivo:** Limitação de 1 consulta de IA por ciclo quinzenal por usuário (Ciclo 1: dias 01 a 14; Ciclo 2: dia 15 ao fim do mês).
- **Arquivos:** [`src/ClienteIA.php`](../src/ClienteIA.php), [`assistant.php`](../assistant.php).
- **Métodos:** `ClienteIA::getUsage()`, `ClienteIA::recordUsage()`.
- **Tabelas:** `ai_daily_usage` (`user_id`, `usage_date`, `requests`).

---

### MÓDULO 13: DATA — Importação, Exportação e Backup

#### `DATA-001` — Importação de Extratos Bancários (OFX e CSV)
- **Objetivo:** Upload de arquivos bancários OFX ou CSV com validação rigorosa de segurança, parsing resiliente e sugestão automática de categoria baseada em palavras-chave.
- **Arquivos:** [`import.php`](../import.php), [`importar.php`](../importar.php) (redirect), [`src/ExtratoParser.php`](../src/ExtratoParser.php).
- **Métodos:** `ExtratoParser::parseOfx()`, `ExtratoParser::parseCsv()`, `ExtratoParser::parseCsvStream()`, `ExtratoParser::sugerirCategoria()`.
- **Tabelas:** `transactions` (`category_name`), `categories`, `accounts`.
- **Regras:** Validação rigorosa de upload com detecção de erros via `$_FILES['error']`, verificação de MIME type real (`finfo_file`), inspeção de assinatura anti-binário (rejeição de bytes nulos); Parser OFX com compatibilidade dual (SGML sem tag de fechamento e XML) e conversão de charset (Windows-1252/ISO-8859-1 para UTF-8); Parser CSV com remoção de BOM UTF-8, detecção de delimitadores por amostragem multi-linha, busca resiliente de cabeçalho mesmo após metadados de banco, salvaguarda anti-colisão de colunas e bloqueio estrito contra conversão de datas (DD/MM/AAAA) em valores numéricos (`ExtratoParser::normalizarValor`); Persistência atômica via transação PDO com sanitização estrita anti-XSS (`Security::sanitizeText`) gravando o snapshot imutável `category_name`.
- **Validação:** `tests/test_suite_phase6.php`.

#### `DATA-002` — Backup Completo e Exportação de Dados
- **Objetivo:** Exportação de todos os registros do usuário em JSON estruturado ou CSV de transações com sanitização contra CSV Injection, além de restauração atômica segura.
- **Arquivos:** [`backup.php`](../backup.php), [`src/Security.php`](../src/Security.php).
- **Métodos:** `Security::sanitizeCsvCell()`, `Security::validateBackupUpload()`, `Security::sanitizeText()`, `Security::sanitizeAmount()`, `Security::sanitizeDate()`.
- **Regras:** Proteção CSRF obrigatória; validação estrita anti-ZIP/compactados, anti-SQL injection, anti-XSS e anti-NUL byte; substituição atômica completa dos dados do usuário logado na restauração (evitando duplicações e clonagem).
- **Validação:** `tests/test_suite_phase6.php`, `tests/test_suite_backup_security.php`.

---

### MÓDULO 14: API — API RESTful v1 (Mobile e Integrações)

#### `API-001` — Autenticação e Console Interativo da API
- **Objetivo:** Console web para geração de token e autenticação via Bearer Token.
- **Arquivos:** [`api/v1/index.php`](../api/v1/index.php), [`src/ApiAuth.php`](../src/ApiAuth.php), [`src/ApiResponse.php`](../src/ApiResponse.php).
- **Métodos:** `ApiAuth::authenticate()`, `ApiAuth::extractToken()`, `ApiResponse::json()`.
- **Tabelas:** `users` (`api_token`).
- **Validação:** `tests/test_suite_phase5.php`.

#### `API-002` — Endpoints RESTful
- **Objetivo:** Endpoints CRUD para clientes móveis e integrações:
  - `GET/POST/DELETE /api/v1/transacoes.php` (Soft Delete em DELETE com `visivel = 0`, snapshot de categoria, filtro `visivel = 1` e normalização estrita de receitas com `$creditCardId = null` e urgência padrão)
  - `GET /api/v1/contas.php` (cálculo de saldo consolidado filtrado com `visivel = 1`)
  - `GET /api/v1/categorias.php` (apenas categorias ativas `visivel = 1` e transações ativas)
  - `GET /api/v1/resumo.php` (indicadores e totais consolidados respeitando `visivel = 1`)
- **Arquivos:** [`api/v1/transacoes.php`](../api/v1/transacoes.php), [`api/v1/contas.php`](../api/v1/contas.php), [`api/v1/categorias.php`](../api/v1/categorias.php), [`api/v1/resumo.php`](../api/v1/resumo.php).
- **Formato:** JSON com envelopes `{ success: bool, data: mixed, error: string }`.

#### `API-003` — Sincronização Delta Offline
- **Objetivo:** Endpoint para sincronização bidirecional de dados com idempotência transacional por `client_id` (índice UNIQUE `user_id, client_id`) e savepoints concorrentes.
- **Arquivos:** [`api/v1/sync.php`](../api/v1/sync.php), [`src/Security.php`](../src/Security.php).

---

### MÓDULO 15: PWA — Progressive Web App e Suporte Offline

#### `PWA-001` — Service Worker e Estratégia de Cache
- **Objetivo:** Suporte a instalação como aplicativo, cache de assets estáticos e tela de fallback offline segura.
- **Arquivos:** [`sw.js`](../sw.js), [`manifest.json`](../manifest.json), [`offline.html`](../offline.html).
- **Estratégia:** Network-Only para navegações HTML (sem armazenamento de dados autenticados em cache) com fallback seguro para `offline.html`; Cache restrito a assets estáticos (`droma-cache-v18`).
- **Validação:** `tests/test_suite_phase6.php`.

---

### MÓDULO 16: NOTIF — Notificações e Lembretes Automáticos

#### `NOTIF-001` — Lembrete Diário de Contas a Vencer por E-mail
- **Objetivo:** Rotina automatizada diária disparada às 00:01 (horário de Brasília) via Webhook/Cron para notificar usuários sobre contas a pagar e parcelas de cartão com vencimento no dia (e pendências recentes em atraso).
- **Arquivos:** [`cron/notify_bills.php`](../cron/notify_bills.php), [`src/Mailer.php`](../src/Mailer.php), [`index.php`](../index.php).
- **Métodos:** `Mailer::sendBillDueReminder()`.
- **Tabelas:** `daily_notifications`, `transactions`, `installments`, `users`.
- **Regras:**
  - Idempotência diária gravada na tabela `daily_notifications` (evita envios repetidos na mesma data).
  - Autenticação mandatória por token secreto (`CRON_SECRET`) via header `X-Cron-Token` ou parâmetro `?token=` (retorna HTTP 500 se o secret não estiver configurado no servidor, sem token padrão em código).
  - Suporte a modo teste com parâmetro `&force=1` (preserva idempotência oficial).
  - Alerta visual complementar em banner de destaque no Dashboard (`index.php`).
  - Proteção de privacidade (LGPD): supressão de identificadores de usuários e e-mails na resposta HTTP, mantendo logs detalhados restritos à execução via CLI.

---

### MÓDULO 17: GOV — Governança de Agentes IA & Economia Extrema de Tokens

#### `GOV-001` — Protocolo Universal de Governança e Redução Extrema de Tokens (v4.0-Universal)
- **Objetivo:** Maximização de desempenho, autonomia operacional sem paradas, eliminação de alucinações arquiteturais via Map-First e redução de 65% a 92% no consumo de tokens para qualquer LLM/agente.
- **Arquivos:** [`AGENTS.md`](../AGENTS.md), [`HANDOFF.md`](../HANDOFF.md), [`docs/MANUAL_GOVERNANCA_IA_E_ECONOMIA_TOKENS.md`](MANUAL_GOVERNANCA_IA_E_ECONOMIA_TOKENS.md), [`docs/MANUAL_GOVERNANCA_IA_E_ECONOMIA_TOKENS.pdf`](MANUAL_GOVERNANCA_IA_E_ECONOMIA_TOKENS.pdf).
- **Regras:**
  - **Autonomia Operacional Direta:** Execução sem pausas desnecessárias, interrompendo apenas por ambiguidade crítica, ação destrutiva irreversível ou violação de regra inviolável.
  - **Navegação Map-First Mandatória:** Consulta prévia a `docs/MAPA_DO_SISTEMA.md` antes de qualquer comando ou edição.
  - **Leitura Fatiada & Edição Cirúrgica:** Uso estrito de `StartLine`/`EndLine` e `edit_file` com 1 a 3 linhas de ancoragem (proibido `write_file` em arquivos existentes).
  - **Saída Zero-Slop:** Eliminação total de saudações e conclusões prolixas, iniciando no 1º caractere técnico útil.
  - **Checkpoint Compacto [STATE]:** Resumo estruturado em texto plano em ≤ 15 linhas.
  - **Semáforo Multi-Agente:** Arquivo `HANDOFF.md` para reserva atômica de arquivos em concorrência.
  - **Checklist Inviolável de Conclusão:** 7 verificações obrigatórias antes de finalizar qualquer intervenção.

---

## 4. Estrutura do Banco de Dados (MySQL 8.4)

| Tabela | Propósito | Chave Primária | Relacionamentos Principais |
| :--- | :--- | :--- | :--- |
| **`users`** | Contas de usuários e credenciais | `id` | Raiz de todo o isolamento multi-tenant |
| **`accounts`** | Contas correntes, poupanças e carteiras | `id` | `user_id -> users.id` (CASCADE) |
| **`categories`** | Categorias de receitas e despesas | `id` | `user_id -> users.id` (CASCADE) |
| **`credit_cards`** | Cartões de crédito | `id` | `user_id -> users.id`, `account_id -> accounts.id` |
| **`transactions`** | Lançamentos de receitas e despesas | `id` | `user_id`, `account_id`, `category_id`, `credit_card_id`, `investment_id` |
| **`installments`** | Parcelas de compras no cartão | `id` | `transaction_id -> transactions.id`, `credit_card_id` |
| **`goals`** | Metas de economia financeira | `id` | `user_id -> users.id` (CASCADE) |
| **`investments`** | Ativos financeiros e carteira | `id` | `user_id -> users.id`, `account_id -> accounts.id` |
| **`recurring_transactions`**| Assinaturas e lançamentos automáticos | `id` | `user_id`, `account_id`, `category_id` |
| **`daily_notifications`**| Idempotência e histórico de notificações diárias | `id` | `user_id -> users.id`, UNIQUE `(user_id, type, date_sent)` |
| **`ai_daily_usage`** | Registro e quota do assistente IA | `(user_id, usage_date)` | `user_id -> users.id` (CASCADE) |
| **`rate_limits`** | Proteção contra ataques de força bruta | `id` | `rate_key` UNIQUE (Baseado em IP e Ação) |
| **`tags`** | Tags transversais por usuário | `id` | `user_id -> users.id`, UNIQUE `(user_id, name)` |
| **`transaction_tags`** | Associação N:N entre lançamentos e tags | `(transaction_id, tag_id)` | `transaction_id`, `tag_id` |
| **`transaction_splits`** | Divisão de lançamento em múltiplas categorias | `id` | `transaction_id`, `category_id` |
| **`net_worth_snapshots`** | Histórico mensal de patrimônio líquido | `id` | `user_id`, UNIQUE `(user_id, snapshot_month)` |

---

## 5. Como Executar e Validar Testes

O projeto dispõe de 7 suítes de teste de integração e unidade em PHP puro, sem necessidade de PHPUnit externo:

```bash
# Executar suíte completa de testes
php tests/test_suite_phase1.php  # AUTH & Segurança (users, login, csrf, session)
php tests/test_suite_phase2.php  # Transações & Categorias (income, expense, transfer)
php tests/test_suite_phase3.php  # Planejamento & Cartões (cards, installments, recurring, goals)
php tests/test_suite_phase4.php  # Relatórios & IA (ClienteIA, relatórios, quota)
php tests/test_suite_phase5.php  # API RESTful v1 (ApiAuth, endpoints, delta sync)
php tests/test_suite_phase6.php  # PWA & Importação (OFX, CSV, Backup, Service Worker)
php tests/test_suite_phase7.php  # Investimentos & Hierarquia Conta-Cartão
```

Para verificar sintaxe rápida de qualquer arquivo PHP sem executar:
```bash
php -l nome_do_arquivo.php
```
