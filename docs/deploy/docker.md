# Guia de Deploy com Docker — Wert (WertFinancy)

Este guia orienta como subir o sistema **Wert** em qualquer ambiente (Windows, Linux, macOS ou servidores em nuvem como AWS, DigitalOcean, Hetzner, etc.) utilizando **Docker** e **Docker Compose**.

---

## 1. Pré-requisitos

- **Docker Desktop** (no Windows ou macOS) ou **Docker Engine** (no Linux).
- **Docker Compose** v2+ (já incluso no Docker Desktop).

---

## 2. Início Rápido (Executar com 1 Comando)

1. Na raiz do projeto, execute:
   ```bash
   docker compose up -d
   ```

2. O Docker irá:
   - Baixar a imagem do **MySQL 8.4**.
   - Criar e inicializar o banco de dados `wert` executando automaticamente as migrations em `docker/mysql-init/01_schema.sql`.
   - Construir o container com **PHP 8.4 + Apache** com todos os módulos (`pdo_mysql`, `mbstring`, `opcache`, `mod_rewrite`, `headers`).
   - Mapear a aplicação na porta **`8080`**.

3. Acesse no seu navegador:
   ```
   http://localhost:8080
   ```

---

## 3. Variáveis de Ambiente Customizáveis

Você pode customizar portas e senhas criando um arquivo `.env` a partir do modelo:
```bash
cp .env.example .env
```

| Variável | Padrão | Descrição |
|---|---|---|
| `APP_PORT` | `8080` | Porta HTTP exposta no host para acessar o Wert. |
| `DB_PORT_EXTERNAL` | `3307` | Porta externa do MySQL (evita conflito com instalações locais na 3306). |
| `DB_NAME` | `wert` | Nome do banco de dados. |
| `DB_USER` | `wert_user` | Usuário da aplicação no MySQL. |
| `DB_PASS` | `wert_secret` | Senha da aplicação no MySQL. |
| `DB_ROOT_PASS` | `wert_root_secret` | Senha do usuário `root` no container MySQL. |
| `GEMINI_API_KEY` | *(vazio)* | Chave da API do Google Gemini para o Assistente IA. |
| `GEMINI_MODEL` | `gemini-3.5-flash-lite` | Modelo de IA utilizado. |

---

## 4. Comandos Úteis do Dia a Dia

### Ver os logs em tempo real
```bash
docker compose logs -f
```

### Parar os containers sem perder dados
```bash
docker compose down
```

### Reiniciar os containers
```bash
docker compose restart
```

### Executar os testes automatizados dentro do container Docker
```bash
docker compose exec app php tests/test_suite_phase1.php
docker compose exec app php tests/test_suite_phase2.php
docker compose exec app php tests/test_suite_phase3.php
docker compose exec app php tests/test_suite_phase4.php
docker compose exec app php tests/test_suite_phase5.php
docker compose exec app php tests/test_suite_phase6.php
```

### Acessar o terminal do container da aplicação
```bash
docker compose exec app bash
```

### Acessar o MySQL pelo terminal
```bash
docker compose exec db mysql -u wert_user -p wert
```

---

## 5. Persistência de Dados & Backups

- Os dados do banco de dados são armazenados no volume Docker persistente `wert_mysql_data`. Mesmo que os containers sejam destruídos com `docker compose down`, seus dados e cadastros continuam preservados.
- Para efetuar backup rápido do MySQL via Docker:
  ```bash
  docker compose exec db mysqldump -u root -pwert_root_secret wert > backup_docker.sql
  ```
- Para restaurar no container:
  ```bash
  docker compose exec -T db mysql -u root -pwert_root_secret wert < backup_docker.sql
  ```

---

## 6. Publicação em Produção com SSL (Nginx Reverse Proxy)

Para colocar o Wert em produção na nuvem com HTTPS gratuito (Let's Encrypt):
1. Aponte seu domínio (ex: `financeiro.meudominio.com`) para o IP da sua VPS.
2. Utilize o Nginx ou Caddy no servidor host como Proxy Reverso apontando para `http://127.0.0.1:8080`.
3. Gere o certificado SSL via Certbot:
   ```bash
   sudo certbot --nginx -d financeiro.meudominio.com
   ```
