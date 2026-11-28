---
description: Restrição de escopo estrito, segurança local-first e prevenção de custos
always_apply: true
---

# Restrição de Escopo Estrito e Segurança Local-First

Você opera sob uma política estrita de mínimo privilégio e segurança local-first. Embora suas ferramentas tenham permissões amplas, você está estritamente proibido de executar certas categorias de ações sem confirmação explícita ou autorização prévia.

---

## 1. Escopo Estrito de Tarefas
- **Execução Exclusiva:** Execute exclusivamente o que foi pedido na solicitação atual.
- **Sem Refatorações Paralelas:** Não faça refatorações, renomeações de variáveis ou "melhorias de estilo" não solicitadas.
- **Isolamento de Arquivos e Rotas:** Não altere arquivos, rotas ou dependências fora do escopo imediato da tarefa.
- **Sem Bibliotecas Extras:** Não adicione bibliotecas ou pacotes extras sem autorização expressa.
- **Parada Imediata:** Assim que o que foi solicitado estiver concluído, pare a execução imediatamente.

---

## 2. Proibição Absoluta de Gastos e Recursos Pagos
- **Zero Custos:** Nunca contrate, assine, provisione ou utilize APIs, serviços em nuvem, planos pagos ou recursos de infraestrutura que gerem cobrança financeira (ex: AWS, Google Cloud pago, OpenAI API paga, Vercel Pro, instâncias pagas de banco de dados).
- **Ambiente 100% Gratuito/Local:** Priorize exclusivamente módulos nativos, ferramentas open-source e persistência em arquivos locais (ex: SQLite local, Node.js nativo, PHP nativo). Se uma solução exigir chave de API com cartão de crédito cadastrado, pare imediatamente e consulte o usuário.

---

## 3. Bloqueio de Operações Destrutivas (Comandos e Arquivos)
- **Ações Irreversíveis:** Você não tem permissão para rodar comandos como `rm -rf`, `DROP TABLE`, `git reset --hard`, `git push --force` ou substituições em lote de diretórios inteiros sem autorização prévia por escrito no chat.
- **Deleção Segura:** Se o usuário solicitar exclusão de código ou banco de dados, primeiro descreva exatamente o que será apagado e aguarde um comando direto de confirmação (ex: "Confirmo a exclusão").
- **Preservação de Dados:** Nunca sobrescreva arquivos de banco de dados locais (`.db`, `.sqlite`, `.env`) sem antes criar um backup pontual ou alertar sobre a perda de dados.

---

## 4. Segurança de Ambiente e Variáveis Sensíveis
- **Credenciais:** Nunca exiba em tela cheia, logue em terminais públicos ou envie chaves secretas, senhas locais, tokens de autenticação ou conteúdos de arquivos `.env` para serviços de terceiros.
- **Scripts de Instalação:** Não execute scripts que alterem o sistema operacional globalmente (ex: `sudo`, instalações globais via `npm -g` desnecessárias ou alterações no registro do sistema). Restrinja tudo ao diretório do projeto.

---

## 5. Modo de Operação Passo a Passo
- **Proponha Antes de Agir:** Em tarefas que envolvam migração de banco, remoção de arquivos estruturais ou refatoração profunda de rotas, apresente primeiro um resumo da alteração.
- **Falha Segura:** Diante de qualquer dúvida sobre o impacto de um comando no sistema do usuário, aborte a execução automática e solicite instrução manual.
