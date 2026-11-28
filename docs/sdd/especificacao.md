# Especificação Técnica dos Requisitos — Projeto Wert

**Documento:** Especificação Funcional e Não-Funcional  
**Metodologia:** Spec-Driven Development (SDD) — Etapa B  
**Status:** Baseline Atualizado  

---

## 1. Visão Geral e Objetivos do Sistema

O **Wert** (ou **WertFinancy**) é uma plataforma de gestão financeira pessoal desenvolvida em PHP e MySQL. Seu propósito central é conceder aos usuários clareza analítica e controle operacional sobre suas finanças, permitindo:
- Acompanhar saldos consolidados e previsões de fluxo de caixa;
- Registrar receitas e despesas com categorização e vínculos a contas bancárias;
- Gerenciar prazos de vencimento e urgência de pagamentos através de painel de calendário e dashboard;
- Estabelecer metas financeiras com acompanhamento percentual e visual de progresso;
- Evoluir com segurança para controle de cartões de crédito, parcelamentos e relatórios avançados.

---

## 2. Requisitos Não Funcionais (Segurança, Confiabilidade e Performance)

### RNF-01: Segurança de Autenticação e Sessão
- As senhas dos usuários devem ser armazenadas exclusivamente usando funções nativas do PHP `password_hash()` com o algoritmo `PASSWORD_DEFAULT` e verificadas com `password_verify()`.
- O identificador de sessão deve ser regenerado com `session_regenerate_id(true)` imediatamente após a validação das credenciais de login ou criação de conta.
- Cookies de sessão devem ter atributos `httponly`, `samesite` e, em conexões TLS/HTTPS, `secure`.
- Ao efetuar logout, todos os dados da sessão em memória devem ser limpos (`$_SESSION = []`), o cookie invalidado com tempo passado e `session_destroy()` executado.

### RNF-02: Integridade de Dados e Transações Financeiras
- Os valores monetários devem ser manipulados e persistidos exclusivamente no tipo SQL `DECIMAL(12,2)`. Não é permitido o uso de floats nativos imprecisos para cálculo e armazenamento de saldo financeiro.
- Toda operação que envolva modificações interdependentes de tabelas (ex: criação de usuário com conta e categorias iniciais, ou pagamento de fatura futura) deve ser envolvida em uma transação atômica (`$pdo->beginTransaction()`, `$pdo->commit()`, `$pdo->rollBack()`).

### RNF-03: Prevenção de Injeção SQL e Isolamento Multiusuário (Multi-tenant)
- Toda consulta SQL que receba dados variáveis do usuário deve utilizar exclusivamente prepared statements via PDO (`$stmt->prepare()` e `$stmt->execute()`).
- Todas as operações de leitura, escrita, atualização e exclusão em tabelas associadas ao usuário (`accounts`, `categories`, `transactions`, `goals`, etc.) DEVEM obrigatoriamente incluir a cláusula `user_id = ?` obtida da sessão autenticada.
- Não é permitida a persistência de chaves estrangeiras (`account_id`, `category_id`) sem prévia confirmação de que esses registros pertencem ao mesmo `user_id`.

### RNF-04: Proteção Contra Cross-Site Request Forgery (CSRF)
- Qualquer requisição que modifique o estado do sistema (criação, edição, exclusão, alteração de status) DEVE ser transmitida via método `POST`.
- Todas as requisições de mutação devem exigir a validação de um token CSRF criptograficamente seguro (`bin2hex(random_bytes(32))`) validado por meio de comparação em tempo constante (`hash_equals()`).

### RNF-05: Proteção Contra Cross-Site Scripting (XSS)
- Todo e qualquer dado de texto fornecido pelo usuário ou originado do banco de dados deve ser devidamente tratado com `htmlspecialchars($valor, ENT_QUOTES, 'UTF-8')` antes de ser renderizado em contextos HTML.

---

## 3. Especificações Funcionais por Módulo

---

### Módulo 1 — Usuários e Autenticação

#### 1.1 Cadastro de Usuário
- **Objetivo:** Permitir que novos usuários criem uma conta segura no sistema.
- **Entradas:** Nome completo (string 2-100 chars), E-mail (válido, único), Senha (mínimo 6 chars), Confirmação de senha.
- **Saídas:** Redirecionamento ao painel com sessão inicializada ou mensagem de erro explicativa.
- **Regras de Negócio:**
  - O e-mail deve ser normalizado (trim, lowercase) e validado com `FILTER_VALIDATE_EMAIL`.
  - O e-mail não pode existir previamente na tabela `users`.
  - Senha e confirmação devem ser idênticas.
  - Ao cadastrar, o sistema deve criar automaticamente uma conta inicial (`accounts`) do tipo `checking` chamada "Carteira Principal" com saldo 0.00 e 9 categorias essenciais (6 de despesa, 3 de receita).
- **Critérios de Aceitação:**
  - Usuário com dados válidos é inserido no banco com senha criptografada.
  - Uma conta e categorias padrão são geradas atipicamente via transação.
  - Sessão é criada e o usuário entra diretamente no dashboard.
  - Tentativa de duplicar e-mail exibe mensagem amigável sem vazar detalhes de exceção SQL.

#### 1.2 Login e Logout
- **Objetivo:** Autenticar usuários existentes e encerrar acessos de forma segura.
- **Entradas:** E-mail e Senha via POST.
- **Regras de Negócio:**
  - Se autenticado com sucesso, regenerar ID da sessão e armazenar `user_id`, `user_name` e `user_role`.
  - Se credenciais inválidas, redirecionar para tela de login com mensagem genérica "E-mail ou senha incorretos".
  - O logout deve expirar completamente o cookie de sessão e destruir os dados do servidor.
- **Critérios de Aceitação:**
  - Usuário válido acessa `index.php`.
  - Tentativa de acessar rotas protegidas sem sessão redireciona para `login.php`.
  - Logout invalida o acesso imediatamente; botão voltar do navegador não reativa sessão.

#### 1.3 Gestão de Perfil
- **Objetivo:** Permitir que o usuário atualize seus dados de cadastro e altere sua senha.
- **Regras de Negócio:**
  - A alteração de e-mail ou senha requer obrigatoriamente a confirmação da senha atual.
  - O e-mail não pode colidir com o de outro usuário.
  - Toda submissão deve conter e validar token CSRF.
- **Critérios de Aceitação:**
  - Atualização de nome ocorre de forma direta.
  - Alteração de e-mail/senha com senha atual incorreta é rejeitada.
  - Token CSRF inválido interrompe a operação com mensagem de segurança.

---

### Módulo 2 — Dashboard Financeiro

#### 2.1 Indicadores Consolidados (Cards de Saldo)
- **Objetivo:** Exibir visão geral do estado financeiro atual do usuário.
- **Cálculos:**
  - `Saldo Atual`: Soma dos saldos iniciais de todas as contas do usuário + receitas realizadas (`type = 'income' AND status = 'completed'`) - despesas realizadas (`type = 'expense' AND status = 'completed'`).
  - `Entradas do Mês`: Soma de receitas do mês/ano correntes.
  - `Despesas do Mês`: Soma de despesas do mês/ano correntes.
  - `Fim do Mês`: Diferença entre receitas do mês e despesas do mês.
- **Critérios de Aceitação:**
  - Os valores respeitam estritamente o `user_id` autenticado.
  - Se o usuário não tiver transações, os valores exibidos são formatados como `R$ 0,00`.
  - As formatações monetárias utilizam padrão brasileiro (`R$ X.XXX,XX`).

#### 2.2 Gráficos e Distribuição por Categoria
- **Objetivo:** Apresentar visualmente a distribuição de receitas e despesas por categoria no mês atual.
- **Comportamento:**
  - Aba "Despesas": Gráfico de rosca (doughnut) com percentuais e lista detalhada por categoria.
  - Aba "Receitas": Gráfico de rosca com fontes de renda do mês.
  - Aba "Comparativo": Gráfico de barras agrupadas comparando entradas e saídas por categoria.
- **Critérios de Aceitação:**
  - Se não houver dados no mês, exibe mensagem informativa sem quebrar o layout.
  - A alternância entre abas é fluida sem recarregar a página.

#### 2.3 Últimos Lançamentos
- **Objetivo:** Exibir os 8 registros financeiros mais recentes com ações rápidas.
- **Critérios de Aceitação:**
  - Mostra descrição, categoria, data, status (Pago, Pendente, Cancelado), data de vencimento e urgência.
  - Permite acessar diretamente a tela de edição e realizar exclusão segura.

---

### Módulo 3 — Receitas e Despesas (Movimentações)

#### 3.1 Adição de Lançamento
- **Objetivo:** Registrar uma nova entrada ou saída financeira.
- **Entradas:** Tipo (`income` / `expense`), Descrição (opcional, até 255 chars), Valor (decimal positivo > 0), Conta (`account_id`), Categoria (`category_id`), Data do Lançamento (`date`), Data de Vencimento (`due_date`, opcional), Status (`completed` / `pending` / `cancelled`), Urgência (`baixa` / `media` / `alta` para despesas; fixada como `media` para receitas).
- **Regras de Negócio:**
  - A conta e a categoria DEVEM pertencer ao `user_id` autenticado.
  - A categoria selecionada deve ser do mesmo tipo da movimentação (`income` para entradas, `expense` para saídas).
  - O formulário deve possuir token CSRF válido.
- **Critérios de Aceitação:**
  - Transação é gravada na tabela `transactions`.
  - Dados inconsistentes (ex: valor negativo ou IDs de terceiros) são rejeitados com erro legível.
  - O dashboard atualiza os valores imediatamente após a inserção.

#### 3.2 Edição de Lançamento
- **Objetivo:** Modificar campos de um lançamento previamente registrado.
- **Regras de Negócio:**
  - O lançamento informado na URL ou POST deve pertencer ao usuário logado.
  - Validação de propriedade de conta e categoria repetida no ato da atualização.
  - Proteção CSRF obrigatória.
- **Critérios de Aceitação:**
  - O formulário carrega os valores preexistentes corretamente.
  - O registro é atualizado no banco apenas se pertencer ao usuário.
  - Redireciona para o painel ou calendário preservando o feedback de sucesso.

#### 3.3 Exclusão de Lançamento
- **Objetivo:** Remover permanentemente uma movimentação financeira indesejada.
- **Regras de Negócio:**
  - A exclusão DEVE ser disparada exclusivamente via método `POST`.
  - A requisição DEVE apresentar token CSRF válido.
  - O registro DEVE pertencer ao `user_id` da sessão.
- **Critérios de Aceitação:**
  - Requisições GET para a rota de exclusão são sumariamente rejeitadas ou redirecionadas.
  - Transação removida deixa de influenciar o saldo e os relatórios.

#### 3.4 Calendário Financeiro
- **Objetivo:** Visualizar o fluxo de contas a pagar e receber ao longo dos dias do mês.
- **Regras de Negócio:**
  - Permite filtrar por Data de Vencimento (`due_date`) ou Data de Registro (`date`).
  - Apresenta saldo em caixa, previsão de saldo no fim do mês e cards de pendências.
  - Ação rápida de alternar status (toggle: pendente <-> pago) via POST protegido com CSRF.

---

### Módulo 4 — Categorias e Contas

#### 4.1 Gerenciamento de Contas
- **Objetivo:** Permitir cadastro e manutenção de contas (ex: Carteira, Conta Corrente, Poupança).
- **Regras de Negócio:**
  - Tipos suportados: `checking` (Corrente), `savings` (Poupança), `cash` (Dinheiro), `investment` (Investimento).
  - Todo usuário deve possuir no mínimo uma conta ativa.
  - Exclusão de contas com transações vinculadas deve exigir reatribuição ou confirmação com cascata controlada.

#### 4.2 Gerenciamento de Categorias
- **Objetivo:** Permitir ao usuário criar, renomear e organizar categorias próprias.
- **Regras de Negócio:**
  - Cada categoria é classificada como `income` ou `expense`.
  - Não é permitida a duplicação de nome para o mesmo tipo e usuário (`UNIQUE (user_id, name, type)`).

---

### Módulo 5 — Cartões de Crédito e Parcelamentos

#### 5.1 Cartões e Faturas
- **Objetivo:** Controlar limites, faturas e compras parceladas no cartão de crédito sem duplicar despesas na contabilidade de caixa.
- **Regras de Negócio:**
  - Compras no cartão e parcelas futuras são criadas com `status = 'pending'`, consumindo limite do cartão, mas sem subtrair do saldo bancário de caixa.
  - A ação "Pagar Fatura" gera um único lançamento de despesa na conta bancária selecionada e quita as compras/parcelas do ciclo, preservando o histórico contábil sem dupla contagem.

#### 5.2 Lançamentos Recorrentes
- **Objetivo:** Automatizar assinaturas e despesas fixas com periodicidade mensal, semanal ou anual.
- **Regras de Negócio:**
  - Lançamento de recorrência gera a transação e avança a `next_date` para o próximo ciclo de vencimento.

---

### Módulo 6 — Planejamento Financeiro (Metas)

#### 6.1 CRUD de Metas
- **Objetivo:** Registrar objetivos de economia financeira e acompanhar o progresso.
- **Entradas:** Nome da meta, Valor-alvo (`target_amount > 0`), Valor acumulado (`current_amount >= 0`), Prazo final (`deadline`, opcional).
- **Regras de Negócio:**
  - O percentual de progresso é calculado como `min(100, round((current_amount / target_amount) * 100))`.
  - Atualização ágil de valor acumulado via POST.
  - Operações protegidas com token CSRF e isolamento por `user_id`.

---

### Módulo 7 — Análises, Relatórios e Assistente IA

#### 7.1 Relatórios Analíticos
- **Objetivo:** Visão consolidada de fluxo de caixa em 12 meses, composição de contas, percentuais de gastos por categoria e taxa média de poupança.
- **Exportação:** Suporte a impressão via `@media print` e exportação estruturada para CSV com BOM UTF-8.

#### 7.2 Assistente de Inteligência Artificial
- **Objetivo:** Consultoria financeira sob demanda alimentada pelo Google Gemini (`gemini-3.5-flash-lite`).
- **Regras de Negócio:**
  - Cota estrita de 3 requisições por usuário a cada 24 horas (`ai_daily_usage`).
  - Fallback heurístico inteligente local em caso de esgotamento de limite ou falha externa.

---

### Módulo 8 — API RESTful v1.0

#### 8.1 Endpoints JSON
- **Objetivo:** Expor endpoints HTTP (`/api/v1/resumo.php`, `/api/v1/accounts.php`, `/api/v1/categories.php`, `/api/v1/transacoes.php`) para integração com clientes externos.
- **Regras de Negócio:**
  - Autenticação via `Authorization: Bearer <api_token>` ou sessão web ativa.
  - Validação estrita de autorização e proteção contra IDOR em todas as operações de criação e exclusão.
  - Formato JSON padronizado com envelope `{ success, status, data, errors, meta }`.

---

## 4. Cenários de Teste Essenciais (Matriz de Conformidade)

| Cenário de Teste | Entrada / Ação | Resultado Esperado |
| :--- | :--- | :--- |
| **CT-01:** Login com senha correta | E-mail e senha cadastrados válidos | Sucesso, sessão regenerada, redireciona ao `index.php`. |
| **CT-02:** Login com senha incorreta | E-mail válido e senha incorreta | Falha, mensagem amigável, permanece na tela de login. |
| **CT-03:** Tentativa de acesso não autenticado | Acesso direto a `index.php` sem cookie | Redirecionamento 302 imediato para `login.php`. |
| **CT-04:** Criação de transação válida | POST com dados válidos e token CSRF | Transação persistida associada ao `user_id` logado. |
| **CT-05:** Tentativa de CSRF em transação | POST sem token CSRF ou com token adulterado | Requisição rejeitada (HTTP 403 / erro amigável), nada salvo. |
| **CT-06:** Exclusão indevida via GET | Requisição `GET /delete_expense.php?id=X` | Rejeição imediata, nenhuma exclusão executada. |
| **CT-07:** Exclusão legítima via POST | POST com ID pertencente ao usuário e CSRF | Transação excluída com sucesso. |
| **CT-08:** Tentativa de IDOR em conta/categoria | POST com `account_id` pertencente a outro usuário | Rejeição por violação de propriedade; transação não gravada. |
| **CT-09:** Cálculo de Saldo Consolidado | Usuário com saldo inicial 100, receita 50, despesa 30 | Saldo exibido exatamente como R$ 120,00. |
| **CT-10:** Seeding de novo usuário | Novo cadastro via `register.php` | Conta padrão com `type = 'checking'` e 9 categorias criadas com sucesso. |
| **CT-11:** Integridade de Limite de Cartão | Compra no cartão e parcelamento | Limite disponível reduzido sem afetar o saldo em dinheiro da conta. |
| **CT-12:** Rate-Limiting Diário da IA | 4ª requisição no mesmo dia pelo mesmo usuário | Bloqueio de chamada externa e entrega de análise heurística local. |
| **CT-13:** Autenticação da API REST | Requisição sem Bearer Token | Retorno HTTP 401 Unauthorized com payload JSON estruturado. |
| **CT-14:** Criação de Transação via API | POST JSON com dados válidos e Bearer Token | HTTP 201 Created com dados e IDs gerados. |
| **CT-15:** Bloqueio IDOR via API | POST JSON associando ID de conta de terceiro | HTTP 403 Forbidden impedindo vinculação indevida. |

