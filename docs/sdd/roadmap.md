# Roadmap de Evolução — Projeto Wert

**Documento:** Planejamento Estratégico e Ciclo de Vida  
**Metodologia:** Spec-Driven Development (SDD)  
**Status:** Atualizado  

---

## 1. Visão Geral das Fases

```text
[ Fase 0: Auditoria & Documentação ] (Concluída)
        ↓
[ Fase 1: Estabilização & Segurança ] (Em Execução Imediata)
        ↓
[ Fase 2: Núcleo Financeiro Aprimorado ] (Contas, Categorias, Filtros Avançados)
        ↓
[ Fase 3: Crédito & Planejamento ] (Cartões, Faturas, Parcelamento, Recorrências)
        ↓
[ Fase 4: Análise & Relatórios ] (Relatórios Periódicos, Exportação CSV/PDF)
        ↓
[ Fase 5: API REST & Expansão ] (Endpoints JSON, Integrações, PWA)
```

---

## 2. Detalhamento das Fases

### Fase 0 — Auditoria e Documentação (Status: CONCLUÍDA)
- Mapeamento completo dos arquivos do repositório, dependências e ambiente de execução.
- Verificação do status do MySQL 8.4 e integridade das 5 tabelas existentes (`users`, `accounts`, `categories`, `transactions`, `goals`).
- Análise de segurança: identificação de ausência de CSRF em rotas de mutação, exclusão via GET, falta de validação de propriedade em contas/categorias, e inconsistência de `accounts.type`.
- Produção da base SDD em `docs/sdd/`.

---

### Fase 1 — Estabilização e Segurança (Status: EM ANDAMENTO)
- **Objetivo:** Blindar a aplicação contra vulnerabilidades críticas, garantir conformidade com os RNFs e assegurar uma base operacional 100% estável.
- **Entregas:**
  1. Criação do módulo centralizado de segurança `src/Security.php` (CSRF, ownership checks, helpers de sessão).
  2. Correção de `delete_expense.php` para exigir método POST e token CSRF, atualizando formulários em `index.php` e `calendar.php`.
  3. Proteção anti-CSRF e validação de propriedade (`account_id` e `category_id`) em `save_expense.php` e `edit_expense.php`.
  4. Proteção anti-CSRF nas ações de `goals.php` e no toggle de status em `calendar.php`.
  5. Ajuste no cadastro de conta padrão em `register.php` e `add_expense.php` para incluir `type = 'checking'`.
  6. Migration incremental `database/migrations/001_alter_accounts_default_type.sql` para definir default em `accounts.type`.
  7. Ajuste no path do `.env` em `src/ClienteIA.php`.
  8. Bateria de testes de validação das correções.

---

### Fase 2 — Núcleo Financeiro Aprimorado (Status: CONCLUÍDA)
- **Objetivo:** Expandir o controle financeiro do usuário para múltiplas contas e categorias customizadas com filtros avançados.
- **Entregas:**
  1. Tela e CRUD completo de Contas Financeiras (Conta Corrente, Poupança, Dinheiro, Investimentos) com saldo individual e transferência atômica entre contas (`accounts.php`, `transfer.php`).
  2. Tela e CRUD de Categorias Personalizadas com estatísticas de movimentação (`categories.php`).
  3. Extrato completo com filtros avançados (período, conta, categoria, tipo, busca textual e ordenação) (`statement.php`).
  4. Exportação direta para CSV formatado com BOM UTF-8 para Excel.
  5. Testes de integração automatizados da Fase 2 (9/9 passando).

---

### Fase 3 — Crédito e Planejamento Avançado (Status: CONCLUÍDA)
- **Objetivo:** Adicionar a gestão de cartões de crédito e despesas parceladas/recorrentes sem duplicar contabilidade.
- **Entregas:**
  1. Migration `002_create_credit_cards_and_installments.sql` criando `credit_cards`, `installments` e `recurring_transactions`.
  2. Módulo de Cartões de Crédito com cálculo em tempo real de limites e faturas (`cards.php`).
  3. Compras à vista e parceladas no cartão de crédito em até 24x com datas de vencimento sincronizadas com o fechamento (`add_expense.php`, `save_expense.php`).
  4. Pagamento de fatura atômico sem dupla contagem contábil no fluxo de caixa.
  5. Módulo de Lançamentos Recorrentes com efetivação antecipada e avanço automático de ciclo (`recurring.php`).
  6. Integração completa no Calendário Financeiro (`calendar.php`).
  7. Testes de integração automatizados da Fase 3 (6/6 passando).

---

### Fase 4 — Análises, Relatórios e Assistente IA (Status: CONCLUÍDA)
- **Objetivo:** Fornecer inteligência financeira através de relatórios visuais e consultoria via Inteligência Artificial (Gemini).
- **Entregas:**
  1. Migration `003_create_ai_daily_usage.sql` para auditoria e controle de requisições de IA por usuário.
  2. Módulo de Relatórios Analíticos (`reports.php`) com comparativo anual de 12 meses, gráficos Chart.js locais de receitas, despesas e saldo líquido, distribuição por categoria, saldos de contas bancárias e modo de impressão limpo.
  3. Exportação de resumo consolidado em formato CSV.
  4. Módulo Assistente de Inteligência Artificial (`assistant.php`) integrado em tempo real com o Google Gemini (`gemini-3.5-flash-lite`), controle rigoroso de cota de 3 consultas/dia e motor heurístico local de fallback.
  5. Testes de integração automatizados da Fase 4 (8/8 passando).
  6. Total de 35 testes automatizados cobrindo 100% dos fluxos críticos sem qualquer regressão.

---

### Fase 5 — API REST e Expansão (Status: CONCLUÍDA)
- **Objetivo:** Disponibilizar os recursos do Wert via endpoints RESTful estruturados para integrações externas e mobile.
- **Entregas:**
  1. Migration `004_add_api_token_to_users.sql` adicionando suporte a token por usuário.
  2. Classes de infraestrutura: `src/ApiResponse.php` e `src/ApiAuth.php`.
  3. Gerenciamento e regeneração de chaves de API na interface do usuário (`profile.php`).
  4. Endpoints estruturados sob `/api/v1/`:
     - `GET /api/v1/resumo.php` (indicadores e saldo consolidado)
     - `GET /api/v1/accounts.php` (contas e saldos em tempo real)
     - `GET /api/v1/categories.php` (categorias com filtros)
     - `GET, POST, DELETE /api/v1/transacoes.php` (CRUD completo com proteção IDOR)
  5. Console interativo de testes em `/api/v1/index.php`.
  6. Documentação completa em `docs/api/endpoints.md`.
  7. Suíte de testes automatizados da Fase 5 (12/12 passando).
  8. Total geral de 47 testes de integração automatizados com 100% de cobertura dos requisitos críticos do SDD.

---

### Fase 6 — PWA, Importação de Extratos e Backup (Status: CONCLUÍDA)
- **Objetivo:** Potencializar a experiência de uso no mobile (PWA), permitir conciliação em lote via extratos bancários e garantir soberania de dados com backup/restauração.
- **Entregas:**
  1. Configuração completa de PWA: `manifest.json`, Service Worker `sw.js` com cache de assets e ícones responsivos SVG (`img/icon-192.svg`, `img/icon-512.svg`).
  2. Módulo de Importação Inteligente de Extratos (`import.php` e `src/ExtratoParser.php`):
     - Suporte nativo a extratos bancários em formato CSV (bancos brasileiros) e OFX.
     - Inferência automática de categorias baseada em palavras-chave.
     - Tela de conferência prévia e importação atômica em lote.
  3. Módulo de Backup & Restauração de Dados (`backup.php`):
     - Exportação estruturada em JSON com resumo e metadados.
     - Restauração atômica com remapeamento de chaves estrangeiras.
  4. Suporte a Cartões de Crédito no formulário de edição de gastos (`edit_expense.php`).
  5. Suíte de testes automatizados da Fase 6 (12/12 passando).

---

### Fase 7 — Investimentos (Aportes x Retornos & ROI) e Hierarquia Conta-Cartão (Status: CONCLUÍDA)
- **Objetivo:** Estabelecer a relação de dependência estrutural entre Contas (classe pai) e Cartões de Crédito (subclasse filha), além de construir o módulo completo de ativos e investimentos vinculando entradas e saídas financeiras.
- **Entregas:**
  1. Migration `005_link_credit_cards_to_accounts.sql`:
     - Coluna `account_id` obrigatória em `credit_cards`.
     - Restrição de unicidade `uq_credit_cards_account` (regra estrita: 1 cartão por conta).
     - Chave estrangeira `ON DELETE RESTRICT` (bloqueia exclusão de conta com cartão vinculado).
  2. Atualização dos módulos de Contas e Cartões (`accounts.php` e `cards.php`):
     - Exigência de conta cadastrada e seleção obrigatória ao criar cartão.
     - Identificação visual da conta vinculada e badge do cartão correspondente na conta.
     - Bloqueio de exclusão de conta pai enquanto o cartão subordinado existir.
     - Pagar fatura pré-seleciona automaticamente a conta vinculada.
  3. Migration `006_create_investments_and_link_transactions.sql`:
     - Criação da tabela `investments` com tipologia de ativos (Renda Fixa, Ações, FIIs, Cripto, Fundos, Outros).
     - Adição da coluna `investment_id` na tabela `transactions`.
  4. Módulo de Investimentos (`investments.php`):
     - Visão consolidada: Total Aportado, Total Retornado, Lucro Líquido Real e ROI %.
     - Modais para Aportes rápidos, Rendimentos/Proventos periódicos e Resgates de capital.
     - Extrato e histórico filtrado por ativo.
  5. Integração com lançamentos (`add_expense.php`, `save_expense.php` e `edit_expense.php`).
  6. Suporte completo no módulo de Backup e Restauração (`backup.php`).
  7. Suíte de testes automatizados da Fase 7 (`tests/test_suite_phase7.php` - 15/15 aprovados).
  8. **Total consolidado: 74 testes de integração automatizados com 100% de aprovação e 0 erros de sintaxe em todo o repositório.**

