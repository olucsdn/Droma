# Relatório de Auditoria Técnica — Projeto Wert (WertFinancy)

**Data da Auditoria:** 21/10/2026  
**Metodologia:** Spec-Driven Development (SDD) — Etapa A  
**Auditor:** Engenharia de Software Sênior & Arquitetura  
**Ambiente:** Laragon (Windows), PHP 8.3.33 CLI, MySQL 8.4.3 Community Server  

---

## 1. Estrutura Atual do Repositório

```text
C:\laragon\www\Wert\
├── .gitignore                          # Ignora .env
├── config/
│   └── database.php                    # Conexão PDO com MySQL (charset utf8mb4, ERRMODE_EXCEPTION)
├── database/
│   └── ai_daily_usage.sql              # Script DDL da tabela de rate limit para IA
├── js/
│   └── chart.umd.min.js                # Chart.js UMD bundle local (208 KB)
├── src/
│   └── ClienteIA.php                   # Cliente Gemini AI (gemini-3.8-flash)
├── docs/sdd/                           # Documentação da metodologia SDD
├── adicionar_gasto.php                 # Interface para novo lançamento com filtros dinâmicos
├── alter_transactions.sql              # Migration executada para due_date e urgency em transactions
├── cadastrar.php                       # Cadastro de usuário, seeding de conta e categorias padrão
├── calendario.php                      # Painel mensal de contas a pagar/receber e fluxo de caixa
├── documentacao_controle_financeiro_php.pdf # Especificação técnica original do projeto
├── editar_gasto.php                    # Edição de lançamentos existentes
├── excluir_gasto.php                   # Exclusão de lançamentos
├── gerar_hash.php                      # Script de utilitário (geração de hash de senha de teste)
├── index.php                           # Dashboard financeiro principal com gráficos e KPIs
├── login.php                           # Processador POST de autenticação
├── logout.php                          # Encerramento seguro de sessão
├── metas.php                           # Gerenciamento de metas financeiras (CRUD)
├── paginalogar.php                     # Interface de login
├── perfil.php                          # Gestão de perfil do usuário (nome, e-mail, senha com CSRF)
├── recuperar.php                       # Interface para recuperação de senha (placeholder funcional)
├── registro.php                        # Redirect 301 para cadastrar.php
├── salvar_gasto.php                    # Processador POST para inserção de movimentações
├── style_form.css                      # Estilos para formulários
├── style_index.css                     # Estilos do dashboard, calendário e layout global
└── style_login.css                     # Estilos das páginas de autenticação
```

---

## 2. Tecnologias, Versões e Dependências Detectadas

- **Linguagem Principal:** PHP 8.3.33 (64-bit ZTS no ambiente Laragon `C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64`).
- **Banco de Dados:** MySQL Community Server 8.4.3 (porta 3306 ativa, charset `utf8mb4`, collation `utf8mb4_unicode_ci`).
- **Camada de Persistência:** PHP PDO (`PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`).
- **Frontend:** HTML5 semântico, CSS3 com variáveis CSS personalizadas (design system dark mode refinado, tipografia com inter/sans-serif, paleta slate/emerald/amber/rose), JavaScript Vanilla para manipulação DOM e filtros reativos.
- **Bibliotecas Externas:** Chart.js v4.x (distribuição minificada local em `js/chart.umd.min.js`, com fallback para CDN).
- **Controle de Versão:** Arquivo `.gitignore` preexistente; repositório Git ainda não inicializado localmente.

---

## 3. Estrutura e Estado do Banco de Dados (`wert`)

O banco de dados ativo foi examinado diretamente no MySQL 8.4.3. O schema possui integridade referencial com chaves estrangeiras e cascata de deleção (`ON DELETE CASCADE`):

### Tabelas Existentes no MySQL:
1. **`users`** (2 registros existentes: Admin id=1, Teste id=2):
   - `id` (int unsigned, PK, AUTO_INCREMENT)
   - `name` (varchar 100)
   - `email` (varchar 150, UNIQUE)
   - `password` (varchar 255 - compatível com `password_hash`)
   - `role` (enum 'admin', 'user', default 'user')
   - `created_at`, `updated_at` (timestamp)
2. **`accounts`** (2 registros existentes: "Carteira Principal"):
   - `id` (int unsigned, PK, AUTO_INCREMENT)
   - `user_id` (int unsigned, FK -> `users.id` ON DELETE CASCADE)
   - `name` (varchar 100)
   - `type` (enum 'checking', 'savings', 'cash', 'investment', NOT NULL sem default)
   - `initial_balance` (decimal 12,2, default 0.00)
   - `created_at`, `updated_at`
3. **`categories`** (16 registros existentes):
   - `id` (int unsigned, PK, AUTO_INCREMENT)
   - `user_id` (int unsigned, FK -> `users.id` ON DELETE CASCADE)
   - `name` (varchar 100)
   - `type` (enum 'income', 'expense')
   - `created_at`
   - Índice único: `UNIQUE KEY (user_id, name, type)`
4. **`transactions`** (2 registros existentes):
   - `id` (int unsigned, PK, AUTO_INCREMENT)
   - `user_id` (int unsigned, FK -> `users.id` ON DELETE CASCADE)
   - `account_id` (int unsigned, FK -> `accounts.id` ON DELETE CASCADE)
   - `category_id` (int unsigned, FK -> `categories.id` ON DELETE CASCADE)
   - `type` (enum 'income', 'expense')
   - `amount` (decimal 12,2)
   - `description` (varchar 255, NULL)
   - `date` (date)
   - `due_date` (date, NULL)
   - `status` (enum 'pending', 'completed', 'cancelled', default 'completed')
   - `urgency` (enum 'baixa', 'media', 'alta', default 'media')
   - `created_at`, `updated_at`
5. **`goals`** (0 registros):
   - `id` (int unsigned, PK, AUTO_INCREMENT)
   - `user_id` (int unsigned, FK -> `users.id` ON DELETE CASCADE)
   - `name` (varchar 100)
   - `target_amount` (decimal 12,2)
   - `current_amount` (decimal 12,2, default 0.00)
   - `deadline` (date, NULL)
   - `created_at`, `updated_at`

### Tabelas Documentadas e Planejadas para as Próximas Fases:
- `credit_cards` (Cartões de crédito)
- `installments` (Parcelamento de compras e faturas)
- `recurring_transactions` (Lançamentos recorrentes/fixos)
- `audit_logs` (Histórico de ações críticas)
- `ai_daily_usage` (Controle de cota de requisições à IA)

---

## 4. Funcionalidades Já Implementadas e Validadas

| Módulo | Funcionalidade | Estado | Observações Técnicas |
| :--- | :--- | :--- | :--- |
| **M1: Autenticação** | Login por e-mail e senha | Concluído | Utiliza `password_verify`, `session_regenerate_id(true)`, busca segura PDO. |
| **M1: Autenticação** | Cadastro com dados iniciais | Concluído | Cria usuário com hash `PASSWORD_DEFAULT`, transação PDO inicial com conta e 9 categorias padrão. |
| **M1: Autenticação** | Logout | Concluído | Limpa `$_SESSION`, destrói cookie de sessão com expiração passada, redireciona ao login. |
| **M1: Autenticação** | Perfil do Usuário | Concluído | Edição de nome, e-mail e senha. Validação rigorosa de senha atual para mudanças críticas e token CSRF. |
| **M1: Autenticação** | Recuperação de Senha | Parcial | Interface funcional e validação de e-mail, mas o envio de e-mail/token é um mock. |
| **M2: Dashboard** | Visão Financeira Mensal | Concluído | Cards de Saldo Consolidado, Entradas do Mês, Despesas do Mês e Fim do Mês. |
| **M2: Dashboard** | Gráficos Dinâmicos | Concluído | Chart.js com alternância de abas: Despesas, Receitas e Comparativo em barras. |
| **M2: Dashboard** | Últimos Lançamentos | Concluído | Lista 8 últimas transações com badges de status, vencimento e urgência. |
| **M2: Dashboard** | Resumo de Metas | Concluído | Cards com percentual de progresso e barra visual. |
| **M3: Movimentações** | Adição de Lançamento | Concluído | Form com seleção de tipo, conta, categoria dinâmica, datas e urgência. |
| **M3: Movimentações** | Edição de Lançamento | Concluído | Carrega dados existentes e salva com validação de `user_id`. |
| **M3: Movimentações** | Exclusão de Lançamento | Parcial | Funciona, porém vulnerável a execução via `GET` sem token CSRF. |
| **M3: Movimentações** | Calendário Financeiro | Concluído | Visão mensal em grade (35-42 dias), fluxo de vencimento vs data de registro, toggle rápido de status. |
| **M6: Metas** | Gestão de Metas | Concluído | Criação com valor-alvo e prazo, atualização rápida de valor acumulado e exclusão. |

---

## 5. Vulnerabilidades e Riscos Relevantes Encontrados

1. **Exclusão de Lançamentos via GET sem CSRF (Alto Risco)**:
   - *Arquivo:* `excluir_gasto.php`
   - *Problema:* O script aceita exclusão através do método `GET` (`excluir_gasto.php?id=...`). Qualquer requisição forjada (por exemplo, embutida em uma tag `<img src="...">`) pode excluir os dados financeiros do usuário autenticado sem seu consentimento.
   - *Solução:* Exigir método `POST`, validar token CSRF do usuário e manter confirmação na interface.

2. **Ausência de Token CSRF nos Formulários Financeiros (Médio Risco)**:
   - *Arquivos:* `salvar_gasto.php`, `editar_gasto.php`, `metas.php`, `calendario.php (toggle_status)`.
   - *Problema:* Embora `perfil.php` já possua CSRF implementado com excelência, os demais formulários POST não validam tokens anti-CSRF.
   - *Solução:* Centralizar um helper de CSRF reutilizável e aplicá-lo a todos os formulários e ações de modificação de dados.

3. **Insecure Direct Object Reference (IDOR) em Contas e Categorias (Médio Risco)**:
   - *Arquivos:* `salvar_gasto.php`, `editar_gasto.php`
   - *Problema:* O script valida se `account_id` e `category_id` são inteiros (`ctype_digit`), mas não valida se pertencem de fato ao `user_id` autenticado na sessão.
   - *Solução:* Adicionar verificação de pertencimento (`SELECT id FROM accounts WHERE id = ? AND user_id = ?`) antes de gravar ou atualizar a transação.

4. **Incompatibilidade de Schema no Cadastro Automático de Contas (Baixo/Médio Risco)**:
   - *Arquivos:* `cadastrar.php` (L48), `adicionar_gasto.php` (L17)
   - *Problema:* O código executa:
     `INSERT INTO accounts (user_id, name, initial_balance) VALUES (?, 'Carteira Principal', 0.00)`
     A coluna `type` da tabela `accounts` é `NOT NULL` do tipo `ENUM('checking','savings','cash','investment')` e não possui valor default. Em instâncias MySQL com `STRICT_TRANS_TABLES` ativado, essa inserção dispara erro de integridade.
   - *Solução:* Incluir explicitamente o campo `type` com o valor `'checking'` na consulta SQL de criação de conta padrão, e/ou definir `'checking'` como default no schema.

5. **Caminho Incorreto de Configuração da IA (Baixo Risco)**:
   - *Arquivo:* `src/ClienteIA.php` (L6)
   - *Problema:* Aponta para `C:/xampp/segredos/wert.env`, enquanto o ambiente real do projeto é `C:/laragon/segredos/wert.env`.
   - *Solução:* Parametrizar o caminho para verificar `C:/laragon/segredos/wert.env` e variáveis de ambiente locais.

---

## 6. Código e Componentes Reutilizáveis Mapeados

- **Conexão Centralizada PDO (`config/database.php`):** Excelente base, usa charset correto (`utf8mb4`) e modo de exceção.
- **Sistema de Sessão e Autenticação:** `login.php`, `logout.php`, `cadastrar.php` já possuem sanitização de sessões, hashes seguros e tratamento de redirecionamentos.
- **Design System CSS:** `style_index.css`, `style_form.css`, `style_login.css` fornecem um visual coeso, paleta de cores consistente, tipografia e responsividade prontas.
- **Mecanismo de CSRF de `perfil.php`:** Pode ser abstraído para uma função/classe reutilizável aplicável em todo o sistema.
- **Biblioteca Chart.js (`js/chart.umd.min.js`):** Arquivo estático local completo já integrado e configurado com paleta de cores personalizada.

---

## 7. Pendências Imediatas para a Fase 1 (Estabilização)

1. Centralizar funções auxiliares de segurança:
   - Geração e validação de tokens CSRF.
   - Validação de propriedade de registros (pertencimento ao `user_id`).
   - Sanitização e escape seguro de saída.
2. Corrigir a vulnerabilidade de exclusão via GET em `excluir_gasto.php`, exigindo POST + CSRF.
3. Proteger `salvar_gasto.php`, `editar_gasto.php`, `metas.php` e `calendario.php` com tokens CSRF.
4. Corrigir o `INSERT` da tabela `accounts` para informar `type = 'checking'`.
5. Ajustar o caminho de leitura do `.env` em `src/ClienteIA.php`.
