# Lista de Tarefas — Projeto Wert

**Documento:** Backlog de Tarefas e Estado de Execução  
**Metodologia:** Spec-Driven Development (SDD) — Etapa D & E  
**Status Atual:** Fase 1 Concluída e Validada  

---

## Legenda de Status
- `[ ]` Pendente
- `[>]` Em Execução
- `[X]` Concluída e Validada
- `[-]` Bloqueada

---

## Fase 1 — Estabilização e Segurança

### Tarefa 1.1 — Criação do Módulo Central de Segurança (`src/Security.php`)
- **Status:** `[X]` (Concluída em 21/10/2026)
- **Requisito Atendido:** RNF-01, RNF-03, RNF-04, RNF-05
- **Arquivos Afetados:** `src/Security.php` (criado)
- **Resultados:** Implementação completa de `csrfToken()`, `csrfField()`, `validateCsrf()`, `requireAuth()`, `validateAccountOwnership()`, `validateCategoryOwnership()` e `escape()`. Testado isoladamente com 100% de sucesso.

---

### Tarefa 1.2 — Blindagem da Exclusão de Lançamentos contra CSRF e método GET
- **Status:** `[X]` (Concluída em 21/10/2026)
- **Requisito Atendido:** RNF-04, RF 3.3
- **Arquivos Afetados:** `excluir_gasto.php`, `index.php`, `calendario.php`
- **Resultados:** `excluir_gasto.php` agora exige método `POST` e token CSRF válido. `GET` é bloqueado com redirecionamento. Links transformados em formulários POST inline seguros com confirmação no JavaScript em `index.php` e no modal dinâmico de `calendario.php`.

---

### Tarefa 1.3 — Proteção Anti-CSRF e Validação de Propriedade (IDOR) em Lançamentos
- **Status:** `[X]` (Concluída em 21/10/2026)
- **Requisito Atendido:** RNF-03, RNF-04, RF 3.1, RF 3.2
- **Arquivos Afetados:** `adicionar_gasto.php`, `salvar_gasto.php`, `editar_gasto.php`
- **Resultados:** Tokens CSRF injetados e validados nas mutações. Validação de propriedade (`Security::validateAccountOwnership` e `Security::validateCategoryOwnership`) implementada, impedindo associação indevida a contas/categorias de terceiros.

---

### Tarefa 1.4 — Proteção Anti-CSRF em Metas, Calendário e Perfil
- **Status:** `[X]` (Concluída em 21/10/2026)
- **Requisito Atendido:** RNF-04, RF 3.4, RF 6.1
- **Arquivos Afetados:** `metas.php`, `calendario.php`, `perfil.php`
- **Resultados:** Tokens CSRF adicionados aos formulários de criação, atualização e exclusão de metas; ao toggle rápido de status no calendário; e unificados no formulário de perfil.

---

### Tarefa 1.5 — Correção do Schema e Inserção Padrão de `accounts`
- **Status:** `[X]` (Concluída em 21/10/2026)
- **Requisito Atendido:** RNF-02, RF 1.1, RF 4.1
- **Arquivos Afetados:** `cadastrar.php`, `adicionar_gasto.php`, `database/migrations/001_alter_accounts_default_type.sql`
- **Resultados:** Migration `001_alter_accounts_default_type.sql` aplicada com sucesso no MySQL 8.4 definindo default `'checking'`. Consultas SQL em `cadastrar.php` e `adicionar_gasto.php` atualizadas para explicitar `type = 'checking'`.

---

### Tarefa 1.6 — Correção do Caminho do `.env` em `src/ClienteIA.php`
- **Status:** `[X]` (Concluída em 21/10/2026)
- **Requisito Atendido:** RNF-01, Módulo 8
- **Arquivos Afetados:** `src/ClienteIA.php`
- **Resultados:** Método `chave()` ajustado para resolução em cadeia (`getenv`, `C:/laragon/segredos/wert.env`, `C:/xampp/...`, `.env` relativo). Teste de leitura executado com sucesso encontrando a chave configurada.

---

### Tarefa 1.7 — Verificação e Testes Integrados da Fase 1
- **Status:** `[X]` (Concluída em 21/10/2026)
- **Requisito Atendido:** Todos os critérios de aceitação da Fase 1
- **Arquivos Afetados:** Todo o repositório
- **Resultados:**
  1. `php -l` em todos os 18 arquivos PHP: 0 erros.
  2. Suíte de testes automatizados (`test_suite_phase1.php`): 12 testes executados, 12 aprovados (0 falhas).
  3. Schemas e integridade referencial preservados no MySQL.


---

## Fase 2 — Núcleo Financeiro Aprimorado

### Tarefa 2.1 — Módulo de Contas Financeiras (`contas.php`)
- **Status:** `[X]` (Concluída em 22/10/2026)
- **Requisito Atendido:** RF 4.1, RNF-02, RNF-03, RNF-04
- **Arquivos Afetados:** `contas.php` (criado)
- **Resultados:** Implementada interface e processador com listagem e cálculo em tempo real de saldo inicial, receitas, despesas e saldo atual por conta. Criação, edição e exclusão segura implementadas. Bloqueio validado para exclusão da única conta e para contas com lançamentos vinculados.

---

### Tarefa 2.2 — Módulo de Categorias Personalizadas (`categorias.php`)
- **Status:** `[X]` (Concluída em 22/10/2026)
- **Requisito Atendido:** RF 4.2, RNF-03, RNF-04
- **Arquivos Afetados:** `categorias.php` (criado)
- **Resultados:** Listagem categorizada por tipo com volume financeiro e quantidade de lançamentos. Criação com validação de unicidade por usuário e tipo. Edição de nomes e bloqueio preventivo de exclusão para categorias em uso.

---

### Tarefa 2.3 — Transferência entre Contas (`transferir.php`)
- **Status:** `[X]` (Concluída em 22/10/2026)
- **Requisito Atendido:** RF 3.1, RF 4.1, RNF-02, RNF-03, RNF-04
- **Arquivos Afetados:** `transferir.php` (criado)
- **Resultados:** Operação de transferência atômica com transação PDO (`beginTransaction`/`commit`/`rollBack`), criando débito na origem e crédito no destino com saldo neutro global e atualização individual correta das contas.

---

### Tarefa 2.4 — Extrato de Movimentações com Filtros Avançados (`extrato.php`)
- **Status:** `[X]` (Concluída em 22/10/2026)
- **Requisito Atendido:** RF 2.1, RF 3.1, RF 7.1
- **Arquivos Afetados:** `extrato.php` (criado)
- **Resultados:** Filtros combinados por período (este mês, mês anterior, últimos 30 dias, este ano, personalizado), conta, categoria, tipo, status e busca textual. Cálculo dinâmico de KPIs filtrados e exportação para CSV com BOM UTF-8 compatível com Excel.

---

### Tarefa 2.5 — Integração na Navegação do Sistema
- **Status:** `[X]` (Concluída em 22/10/2026)
- **Requisito Atendido:** Usabilidade e consistência
- **Arquivos Afetados:** `index.php`, `calendario.php`, `metas.php`, `adicionar_gasto.php`, `editar_gasto.php`, `excluir_gasto.php`, `salvar_gasto.php`
- **Resultados:** Menus superiores unificados em todas as páginas e links bidirecionais de retorno para o extrato ou painel.

---

### Tarefa 2.6 — Testes Integrados da Fase 2
- **Status:** `[X]` (Concluída em 22/10/2026)
- **Requisito Atendido:** Qualidade e garantia de conformidade SDD
- **Arquivos Afetados:** `tests/test_suite_phase1.php`, `tests/test_suite_phase2.php`
- **Resultados:** 21 testes automatizados executados e 100% aprovados sem erros nem avisos. Linting executado em todos os 22 arquivos PHP do projeto com zero erros.

---

## Fase 3 — Planejamento e Crédito

### Tarefa 3.1 — Migration de Cartões de Crédito, Parcelamentos e Recorrências
- **Status:** `[X]`
- **Requisito Atendido:** RF 5.1, RF 5.2, RF 5.5, RNF-02, RNF-03
- **Arquivos Afetados:** `database/migrations/002_create_credit_cards_and_installments.sql`
- **Descrição:**
  1. Criar tabela `credit_cards` (`id`, `user_id`, `name`, `limit_amount`, `closing_day`, `due_day`, `color`, `active`, timestamps).
  2. Adicionar coluna `credit_card_id` anulável em `transactions` (FK -> `credit_cards(id)` ON DELETE SET NULL).
  3. Criar tabela `installments` (`id`, `transaction_id`, `credit_card_id`, `installment_number`, `total_installments`, `due_date`, `amount`, `status`, timestamps).
  4. Criar tabela `recurring_transactions` (`id`, `user_id`, `account_id`, `category_id`, `type`, `amount`, `description`, `frequency`, `next_date`, `active`, timestamps).
- **Critérios de Aceitação:**
  - Script idempotente e reversível aplicado com sucesso no MySQL 8.4.
  - Chaves estrangeiras e integridade referencial configuradas.

---

### Tarefa 3.2 — Módulo de Cartões de Crédito (`cartoes.php`)
- **Status:** `[X]`
- **Requisito Atendido:** RF 5.1, RF 5.2, RF 5.3, RF 5.4
- **Arquivos Afetados:** `cartoes.php` (novo)
- **Descrição:**
  1. Tela de gerenciamento e cartões visuais (com estilo moderno, limite total, limite utilizado e limite disponível).
  2. CRUD de cartões de crédito.
  3. Visualização da fatura do mês (compras à vista no cartão + parcelas do mês).
  4. Ação de "Pagar Fatura": registra despesa na conta bancária e marca as parcelas/compras como quitadas, **sem duplicar a despesa nos relatórios**.
- **Critérios de Aceitação:**
  - Limite disponível calculado dinamicamente com base nas despesas não pagas.
  - Pagamento de fatura debita a conta escolhida e baixa os lançamentos do cartão.

---

### Tarefa 3.3 — Lançamento de Compras no Cartão e Parcelamento
- **Status:** `[X]`
- **Requisito Atendido:** RF 5.3, RF 5.5
- **Arquivos Afetados:** `adicionar_gasto.php`, `salvar_gasto.php`
- **Descrição:**
  1. Permitir ao usuário escolher se o meio de pagamento é "Conta Bancária" ou "Cartão de Crédito".
  2. Quando for cartão, exibir campo de "Número de Parcelas" (1x até 24x).
  3. Ao salvar, se parcelado, gerar as parcelas em `installments` com as respectivas datas de vencimento calculadas a partir do dia de fechamento e vencimento do cartão.
- **Critérios de Aceitação:**
  - Compra parcelada gera as N parcelas com valores e datas corretas.
  - Compra no cartão não afeta o saldo bancário imediato até o pagamento da fatura.

---

### Tarefa 3.4 — Módulo de Lançamentos Recorrentes (`recorrencias.php`)
- **Status:** `[X]`
- **Requisito Atendido:** RF 5.6
- **Arquivos Afetados:** `recorrencias.php` (novo)
- **Descrição:**
  1. Interface para cadastro de despesas e receitas fixas (mensais, anuais).
  2. Exibição das próximas ocorrências e botão para gerar lançamento antecipadamente se desejado.
- **Critérios de Aceitação:**
  - CRUD funcional com token CSRF e isolamento por usuário.

---

### Tarefa 3.5 — Integração no Calendário, Extrato e Menu
- **Status:** `[X]`
- **Requisito Atendido:** Visão integrada
- **Arquivos Afetados:** `calendario.php`, `extrato.php`, `index.php`
- **Descrição:**
  1. Exibir faturas e parcelas de cartões no `calendario.php` com badge distintivo.
  2. Incluir filtro por Cartão de Crédito no `extrato.php`.
  3. Adicionar "Cartões" e "Recorrências" na barra de navegação global.

---

### Tarefa 3.6 — Testes Integrados da Fase 3
- **Status:** `[X]`
- **Requisito Atendido:** Qualidade e garantia SDD
- **Arquivos Afetados:** `tests/test_suite_phase3.php`
- **Descrição:** Testes automatizados cobrindo limites de cartão, faturas, parcelamentos e integridade contábil sem dupla contagem.

---

## Fase 4 — Análises, Relatórios e Assistente IA

### Tarefa 4.1 — Módulo de Relatórios Analíticos (`relatorios.php`)
- **Status:** `[X]`
- **Requisito Atendido:** RF 2.6, RNF-01
- **Arquivos Afetados:** `relatorios.php` (novo), navegação global
- **Descrição:**
  1. Relatório de fluxo de caixa comparativo anual (mês a mês: Receitas, Despesas, Saldo Operacional, Poupança).
  2. Distribuição percentual e absoluta de despesas por categoria no período selecionado.
  3. Distribuição percentual de receitas por categoria.
  4. Composição de saldos e movimentação por conta bancária.
  5. Gráficos integrados utilizando o Chart.js local (`js/chart.umd.min.js`) com a paleta de luxo do Wert.
  6. Opção de impressão e exportação rápida de relatórios.
- **Critérios de Aceitação:**
  - Totais batem exatamente com as somas reais da tabela `transactions`.
  - Despesas de cartão não geram duplicidade contábil.
  - Gráficos renderizam perfeitamente sem erros de script.

---

### Tarefa 4.2 — Assistente de Inteligência Artificial (`assistente.php` / Integração)
- **Status:** `[X]`
- **Requisito Atendido:** RF 6.1, RF 6.2, RF 6.3, RF 6.4, RNF-03
- **Arquivos Afetados:** `database/migrations/003_create_ai_daily_usage.sql`, `src/ClienteIA.php`, `assistente.php` (novo), navegação global
- **Descrição:**
  1. Aplicar a migration `003_create_ai_daily_usage.sql` no MySQL com tipos compatíveis (`user_id INT UNSIGNED`).
  2. Ajustar `src/ClienteIA.php` com suporte a modelo compatível (`gemini-3.5-flash-lite`), tratamento de deprecation PHP 8.5 e rate limiting de 3 chamadas/dia.
  3. Tela `assistente.php` permitindo ao usuário solicitar diagnósticos financeiros periódicos.
  4. Respostas acolhedoras, objetivas, sem juízo moral e com 3 recomendações realistas.
  5. Fallback heurístico inteligente local em caso de esgotamento de cota diária ou falha de conexão com a API externa.
- **Critérios de Aceitação:**
  - Limite diário de 3 requisições respeitado rigorosamente por usuário.
  - Nenhum dado confidencial sensível é exposto.
  - Testado com sucesso em tempo real com a API Gemini do Google.

---

### Tarefa 4.3 — Testes Integrados da Fase 4
- **Status:** `[X]`
- **Requisito Atendido:** Qualidade e garantia SDD
- **Arquivos Afetados:** `tests/test_suite_phase4.php`
- **Descrição:** Testes automatizados cobrindo relatórios analíticos, queries de agregação anual/mensal, tabela `ai_daily_usage`, rate limiting da IA e fallback heurístico.

---

## Fase 5 — API REST, Exportação & Expansão

### Tarefa 5.1 — Arquitetura da API REST & Helper de Respostas JSON / Autenticação
- **Status:** `[X]`
- **Requisito Atendido:** RF 7.1, RF 7.2, RNF-02, RNF-03
- **Arquivos Afetados:** `database/migrations/004_add_api_token_to_users.sql`, `src/ApiResponse.php`, `src/ApiAuth.php`, `perfil.php`
- **Descrição:**
  1. Migration 004 adicionando `api_token VARCHAR(64) NULL UNIQUE` na tabela `users`.
  2. Implementação de `ApiResponse.php` com suporte a CORS, envelopes JSON semânticos e códigos HTTP (200, 201, 400, 401, 403, 404, 500).
  3. Implementação de `ApiAuth.php` com autenticação dupla: via cabeçalho `Authorization: Bearer <token>` ou sessão web ativa.
  4. Interface no `perfil.php` para geração, cópia e regeneração da chave de API com token CSRF.

---

### Tarefa 5.2 — Endpoints RESTful (`/api/v1/`)
- **Status:** `[X]`
- **Requisito Atendido:** RF 7.1, RF 7.2, RF 7.3, RF 7.4
- **Arquivos Afetados:** `api/v1/resumo.php`, `api/v1/contas.php`, `api/v1/categorias.php`, `api/v1/transacoes.php`, `api/v1/index.php`
- **Descrição:**
  1. `GET /api/v1/resumo.php`: Retorna saldo consolidado, receitas e despesas do mês e contadores gerais.
  2. `GET /api/v1/contas.php`: Retorna contas e saldos calculados em tempo real.
  3. `GET /api/v1/categorias.php`: Retorna categorias com contagem de lançamentos e filtro por tipo.
  4. `GET, POST, DELETE /api/v1/transacoes.php`: CRUD completo com paginação, filtros e proteção estrita contra IDOR.
  5. `api/v1/index.php`: Console interativo com documentação dos endpoints e testador em tempo real.

---

### Tarefa 5.3 — Documentação Completa da API
- **Status:** `[X]`
- **Requisito Atendido:** Documentação e integridade SDD
- **Arquivos Afetados:** `docs/api/endpoints.md`
- **Descrição:** Especificação detalhada de métodos, cabeçalhos, parâmetros de URL, corpos JSON de requisição e resposta para todos os endpoints.

---

### Tarefa 5.4 — Testes Automatizados da API e Auditoria Final SDD
- **Status:** `[X]`
- **Requisito Atendido:** Qualidade total e conformidade SDD
- **Arquivos Afetados:** `tests/test_suite_phase5.php`
- **Descrição:** Bateria com 12 testes automatizados cobrindo rejeição 401 sem token, rejeição com token inválido, consulta 200 OK, criação 201 Created, bloqueio 403 Forbidden em IDOR e exclusão 200/404.

---

## Fase 6 — PWA, Importação de Extratos e Backup (Produtividade & Expansão)

### Tarefa 6.1 — Suporte a PWA & Service Worker
- **Status:** `[X]`
- **Requisito Atendido:** Usabilidade mobile, RNF-01
- **Arquivos Afetados:** `manifest.json`, `sw.js`, `img/icon-192.svg`, `img/icon-512.svg`, `index.php`
- **Descrição:**
  1. Criação do arquivo `manifest.json` com tema luxo, display standalone, ícones e cores do Wert.
  2. Implementação de Service Worker (`sw.js`) para cache de assets estáticos (CSS, JS, ícones).
  3. Registro automático do Service Worker e meta tags de suporte PWA no mobile.

---

### Tarefa 6.2 — Módulo de Importação de Extratos Bancários (`importar.php`)
- **Status:** `[X]`
- **Requisito Atendido:** RF 3.1, RF 4.2
- **Arquivos Afetados:** `src/ExtratoParser.php`, `importar.php`, `extrato.php`
- **Descrição:**
  1. Upload e parser de extratos bancários em formato CSV ou OFX com detecção automática de delimitador.
  2. Mapeamento inteligente de colunas (Data, Descrição, Valor, Tipo) e inferência de categorias.
  3. Tela de conferência prévia dos lançamentos permitindo selecionar categoria e desmarcar itens indesejados.
  4. Gravação atômica em lote com validação de conta e integridade.

---

### Tarefa 6.3 — Módulo de Backup & Restauração de Dados (`backup.php`)
- **Status:** `[X]`
- **Requisito Atendido:** Segurança da informação, LGPD / Portabilidade de dados
- **Arquivos Afetados:** `backup.php` (novo), `perfil.php`
- **Descrição:**
  1. Exportação completa dos dados do usuário (contas, categorias, transações, cartões, parcelas, metas, recorrências) em arquivo JSON estruturado.
  2. Importação e restauração de backup com validação de schema e transação atômica.

---

### Tarefa 6.4 — Suporte a Cartões de Crédito em `editar_gasto.php`
- **Status:** `[X]`
- **Requisito Atendido:** RF 5.3, RF 3.3
- **Arquivos Afetados:** `editar_gasto.php`
- **Descrição:**
  1. Suporte à edição de movimentações associadas a cartões de crédito no formulário de edição.
  2. Validação de propriedade do cartão via `Security::validateCreditCardOwnership`.

---

### Tarefa 6.5 — Testes Integrados da Fase 6
- **Status:** `[X]`
- **Requisito Atendido:** Qualidade total SDD
- **Arquivos Afetados:** `tests/test_suite_phase6.php`
- **Descrição:** Bateria de testes automatizados cobrindo geração de backup JSON, restauração, parser de extrato e edição de gastos com cartão.
