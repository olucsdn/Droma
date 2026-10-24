# Documentação da API RESTful — Wert (v1.0)

A API REST do Wert permite a integração segura de clientes externos, aplicativos móveis e rotinas de automação com o sistema financeiro pessoal.

## Autenticação

Todas as requisições aos endpoints da API exigem autenticação. Você pode autenticar de duas maneiras:

1. **Bearer Token (Recomendado):**
   Envie o token de API gerado no seu perfil no cabeçalho HTTP `Authorization`:
   ```http
   Authorization: Bearer seu_token_de_64_caracteres_aqui
   ```

2. **Header Alternativo:**
   ```http
   X-API-Key: seu_token_de_64_caracteres_aqui
   ```

3. **Sessão Web:**
   Se a chamada for originada de um script no mesmo domínio do painel web, a sessão de login ativa do usuário é utilizada automaticamente.

---

## Formato Padrão das Respostas

Todas as respostas são retornadas em formato `application/json; charset=UTF-8` seguindo o envelope padrão:

```json
{
  "success": true,
  "status": 200,
  "data": { ... },
  "errors": null,
  "meta": { ... }
}
```

---

## Endpoints Disponíveis

### 1. Resumo Financeiro
- **URL:** `GET /api/v1/resumo.php`
- **Descrição:** Retorna o saldo consolidado acumulado, totais de receitas e despesas do mês vigente, taxa de poupança e status financeiro.
- **Exemplo de Resposta (200 OK):**
```json
{
  "success": true,
  "status": 200,
  "data": {
    "periodo": "2026-10",
    "saldo_consolidado": 14250.75,
    "mes_atual": {
      "receitas": 7500.00,
      "despesas": 3200.00,
      "saldo_operacional": 4300.00,
      "taxa_poupanca_pct": 57.3,
      "status": "superavit"
    },
    "totais_gerais": {
      "contas_cadastradas": 3,
      "cartoes_cadastrados": 2,
      "faturas_cartao_pendentes": 1280.40
    }
  }
}
```

---

### 2. Listagem de Contas
- **URL:** `GET /api/v1/accounts.php`
- **Descrição:** Lista todas as contas financeiras do usuário com seus respectivos saldos atuais calculados.
- **Exemplo de Resposta (200 OK):**
```json
{
  "success": true,
  "status": 200,
  "data": {
    "contas": [
      {
        "id": 1,
        "name": "Carteira Principal",
        "type": "checking",
        "initial_balance": 500.00,
        "total_receitas": 7500.00,
        "total_despesas": 3200.00,
        "current_balance": 4800.00,
        "total_transacoes": 24,
        "created_at": "2026-10-01 10:00:00"
      }
    ],
    "total_saldo": 4800.00,
    "quantidade": 1
  }
}
```

---

### 3. Listagem de Categorias
- **URL:** `GET /api/v1/categories.php`
- **Parâmetros Query Opcionais:**
  - `type`: `income` ou `expense`
- **Exemplo de Resposta (200 OK):**
```json
{
  "success": true,
  "status": 200,
  "data": {
    "categorias": [
      {
        "id": 1,
        "name": "Alimentação",
        "type": "expense",
        "total_transacoes": 15,
        "volume_financeiro": 1250.00,
        "created_at": "2026-10-01 10:00:00"
      }
    ],
    "total": 1
  }
}
```

---

### 4. Transações Financeiras (CRUD)

#### 4.1. Listar Transações
- **URL:** `GET /api/v1/transacoes.php`
- **Parâmetros Query:**
  - `limit`: Quantidade máxima de registros (padrão 50, máx 200).
  - `offset`: Deslocamento para paginação (padrão 0).
  - `type`: `income` ou `expense`.
  - `status`: `completed`, `pending` ou `cancelled`.
  - `account_id`: Filtrar por conta específica.
  - `category_id`: Filtrar por categoria.
  - `credit_card_id`: Filtrar por cartão de crédito.
  - `busca`: Termo de busca textual na descrição.
  - `data_inicio`: Data mínima no formato `AAAA-MM-DD`.
  - `data_fim`: Data máxima no formato `AAAA-MM-DD`.

#### 4.2. Consultar Transação por ID
- **URL:** `GET /api/v1/transacoes.php?id=123`
- **Respostas:**
  - `200 OK`: Dados completos da transação.
  - `404 Not Found`: Caso a transação não exista ou não pertença ao usuário.

#### 4.3. Criar Transação
- **URL:** `POST /api/v1/transacoes.php`
- **Headers:** `Content-Type: application/json`
- **Corpo da Requisição (JSON):**
```json
{
  "description": "Supermercado Mensal",
  "amount": 450.90,
  "type": "expense",
  "date": "2026-10-22",
  "account_id": 1,
  "category_id": 2,
  "status": "completed",
  "urgency": "media"
}
```
- **Respostas:**
  - `201 Created`: Transação persistida com sucesso e dados retornados.
  - `400 Bad Request`: Dados inválidos ou campos obrigatórios ausentes.
  - `403 Forbidden`: Tentativa de associar a conta ou categoria de outro usuário.

#### 4.4. Excluir Transação
- **URL:** `DELETE /api/v1/transacoes.php?id=123`
- **Respostas:**
  - `200 OK`: Transação e eventuais parcelas associadas removidas com integridade.
  - `404 Not Found`: ID inexistente ou sem permissão de exclusão.

---

## Códigos de Status HTTP

| Código | Significado |
|---|---|
| `200 OK` | Operação de consulta ou exclusão executada com sucesso. |
| `201 Created` | Novo recurso criado com sucesso. |
| `400 Bad Request` | Parâmetros inválidos ou violação de regras de validação. |
| `401 Unauthorized` | Chave de API ausente ou inválida. |
| `403 Forbidden` | Tentativa de acesso a recurso de outro usuário (proteção contra IDOR). |
| `404 Not Found` | Registro não localizado no banco. |
| `405 Method Not Allowed` | Método HTTP não suportado pelo endpoint. |
| `500 Internal Error` | Falha interna no servidor ou banco de dados. |

