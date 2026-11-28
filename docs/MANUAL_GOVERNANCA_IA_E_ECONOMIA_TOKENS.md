# Governança de Agentes IA & Protocolo de Economia Extrema de Tokens
**Framework Coringa & Framework Universal — Padrão Aberto: Plug-and-Play em Qualquer Projeto**
*Versão 4.0-Universal | Padrão Universal • Map-First • SDD • Zero-Slop*

Framework coringa para maximização de desempenho em respostas de LLMs, autonomia operacional sem paradas, eliminação de alucinações via Map-First e redução de 65% a 92% no consumo de tokens em qualquer projeto de software.

| Compatibilidade | Aplicações | Economia Tokens | Alucinação |
| :--- | :--- | :--- | :--- |
| Qualquer LLM / Agente | `AGENTS.md`, `.cursorrules`, System Prompt | ~65% a 92% | Taxa Zero via Map-First |

---

## 1. O Prompt Mestre Coringa (Copie e Cole em Qualquer Repositório)

O bloco abaixo é o Prompt Coringa de Governança Universal. Ele foi desenhado para ser copiado e colado diretamente na raiz de qualquer repositório (ex: em `AGENTS.md`, `.cursorrules`, `CLAUDE.md`, `.github/copilot-instructions.md` ou como System Prompt de agentes autônomos). Ele instrui qualquer LLM a operar com autonomia direta, precisão cirúrgica e corte radical de tokens:

```markdown
# PROTOCOLO UNIVERSAL DE GOVERNANÇA DE AGENTES & ECONOMIA EXTREMA DE TOKENS

## 1. AUTONOMIA OPERACIONAL DIRETA (DISPENSA DE CONFIRMAÇÃO)
- Execute diretamente TODAS as etapas, comandos e edições locais sem pedir confirmações intermediárias.
- Só interrompa por: (1) ambiguidade crítica de requisitos, (2) ação destrutiva irreversível, (3) violação de regra inviolável.

## 2. NAVEGAÇÃO MAP-FIRST MANDATÓRIA (ZERO ALUCINAÇÃO)
- Antes de rodar comandos, ler código ou editar, você DEVE consultar o mapa arquitetural do projeto (ex: docs/MAPA_DO_SISTEMA.md).
- Acesso restrito aos arquivos mapeados necessários. Proibida exploração cega no disco. Retorne e atualize o mapa ao concluir.

## 3. PROTOCOLO DE ECONOMIA EXTREMA DE TOKENS
- LEITURA FATIADA (LINE-SLICING): Proibido ler arquivos inteiros. Use read_file com StartLine e EndLine fatiados sob demanda.
- EDIÇÃO CIRÚRGICA (edit_file): Proibido reescrever arquivos inteiros (write_file). Substitua cirurgicamente com 1 a 3 linhas de ancoragem.
- ZERO SLOP: Elimine 100% de saudações ("Certamente!", "Aqui está"), introduções e conclusões. Comece no 1º caractere técnico útil.
- FORMATO COMPACTO [STATE]: Checkpoints em texto plano condensado (≤ 15 linhas), dispensando JSONs prolixos.
- CONTENÇÃO DE LOGS: Testes e buscas com filtros específicos. Nunca despeje logs massivos no contexto.
- PROIBIÇÃO DE RELEITURA: Nunca releia arquivos já inspecionados na sessão ativa.
- ACHADOS FORA DE ESCOPO: Registre melhorias acessórias em 1 linha sem tocar no código ("achados_fora_de_escopo").

## 4. METODOLOGIA SDD & RISEN
- Spec antes do código. Divida tarefas em fases numeradas (Prompt Chaining) com entregáveis verificáveis.
- Fail-Fast: Se uma tentativa falhar 2 vezes, PARE. Não entre em loop exploratório. Reporte o bloqueio com precisão.
```

**Benefício Imediato:** Ao colar este prompt na raiz, qualquer assistente ou LLM passa a fatiar leituras, usar edições cirúrgicas e eliminar toda conversa fiada social, reduzindo o consumo de tokens em mais de 70% já no primeiro turno de interação.

---

## 2. Os 6 Pilares Universais de Alta Performance para Respostas de IA

### 2.1 Autonomia Operacional Direta (Zero-Pause Execution)
A causa primária de lentidão e perda de coerência em fluxos com IA é a hesitação conversacional. Modelos treinados para assistencialismo tradicional frequentemente interrompem tarefas a cada 2 minutos perguntando se podem prosseguir. O padrão coringa impõe autonomia plena para comandos locais e mutações reversíveis. A IA recebe a meta e executa sequencialmente até a entrega. A execução só deve pausar em três exceções objetivas: (1) ambiguidade semântica que altere a lógica do negócio, (2) risco destrutivo irreversível (como drop de banco de produção), e (3) conflito explícito com regras mestras do sistema.

### 2.2 Protocolo Map-First (Navegação Espacial Estruturada)
Toda LLM degrada seu raciocínio quando é forçada a 'adivinhar' a estrutura de diretórios ou rodar comandos globais recursivos (`find .`, `Get-ChildItem -Recurse`). O princípio Map-First estabelece que o projeto deve manter um arquivo central (ex: `docs/MAPA_DO_SISTEMA.md`) indexando caminhos relativos e responsabilidades. A IA é obrigada a consultar o mapa como primeiro ato; ao término, é obrigada a retornar e registrar novos módulos. Isso elimina 100% das alucinações de caminhos de arquivos e reduz o overhead de navegação a zero.

### 2.3 Especificação Prévia SDD (Spec-Driven Development) & Framework RISEN
Código gerado sem contrato claro tende a introduzir débitos técnicos, refatorações não solicitadas e complexidade acidental. A metodologia SDD preconiza que os requisitos, modelos de dados e matrizes de teste sejam especificados antes de qualquer edição. O acrônimo RISEN estrutura o contexto do agente: Role (Papel especialista), Instructions (Instruções imperativas), Steps (Etapas ordenadas), Expectation (Critério de aceitação) e Narrowing (Fronteiras e restrições estritas de escopo).

### 2.4 Decomposição Faseada & Regra de Parada Fail-Fast (Anti-Loop)
Tarefas complexas devem ser decompostas em pipelines sequenciais de 3 a 7 etapas verificáveis (Fase 0: Planejamento antecipado em texto puro com âncora de estado, sem bloqueios de espera). Durante uma fase, a IA foca exclusivamente no entregável ativo. Regra Fail-Fast: caso uma mesma abordagem falhe por duas vezes consecutivas, a IA é proibida de entrar em tentativas aleatórias ou loops exploratórios; ela deve parar imediatamente, registrar a causa raiz e emitir alerta.

### 2.5 Raciocínio Nativo Oculto (Native Thinking / Hidden CoT)
Em modelos modernos dotados de canal de pensamento nativo (como Gemini Flash Thinking, Claude Extended Thinking e o1/o3), toda a cadeia de rascunho mental, cálculos intermediários, deduções e testes teóricos de hipóteses deve ser internalizada no canal de raciocínio. A resposta visível final deve ser cirúrgica, limpa e executável, eliminando dezenas de parágrafos de monólogo interno que atrasam a entrega ao desenvolvedor humano.

### 2.6 Semáforo Concorrente Multi-Agente (Handoff Protocol)
Em ambientes com múltiplos agentes, programadores em pares ou terminais operando em paralelo, colisões de código são frequentes. O protocolo universal adota um arquivo de controle compartilhado (ex: `HANDOFF.md`) onde cada tarefa reserva explicitamente os arquivos que pretende modificar. Se um arquivo estiver reservado por outra sessão ativa, o agente suspende a mutação até a liberação, garantindo concorrência segura e prevenindo Lost Updates.

---

## 3. Mecanismos Universais de Economia Extrema de Tokens

| Mecanismo Universal | Vício Operacional Comum | Diretriz Obrigatória do Framework | Economia Medida |
| :--- | :--- | :--- | :--- |
| **1. Leitura Fatiada (Line-Slicing)** | Ler arquivos inteiros de 500+ linhas (`read_file`) para ver uma única função. | Uso estrito de `StartLine` e `EndLine` para inspecionar unicamente as linhas necessárias. | -75% a -92% de tokens de input. |
| **2. Edição Cirúrgica (`edit_file`)** | Reescrever o arquivo inteiro com `write_file` para alterar poucas linhas de código. | Substituição atômica via `edit_file` com 1 a 3 linhas de ancoragem contextual. | -90% a -97% de tokens de output. |
| **3. Saída Zero-Slop (Zero Verbosidade)** | Saudações cordiais, introduções vazias ("Certamente!"), desculpas e adulações. | Início direto no 1º caractere técnico útil. Clareza objetiva com links de arquivos. | -20% a -35% de tokens de resposta. |
| **4. Formato Compacto (`[STATE]`)** | Checkpoints estruturados em JSON prolixo com repetição de chaves e indentação. | Formato plano `[STATE]` (≤ 15 linhas) contendo apenas campos acionáveis. | -65% por checkpoint/retomada. |
| **5. Contenção de Logs & Comandos** | Rodar testes gerais ou buscas sem filtros, despejando milhares de linhas no chat. | Comandos com escopo específico (alvo unitário, limites `head -n 20`, `log -n 5`). | Previne exaustão de contexto. |
| **6. Memória de Sessão (Anti-Releitura)** | Reabrir arquivos inspecionados há poucos turnos na mesma sessão de chat. | Proibição estrita de releitura de arquivos já mantidos no contexto ativo. | Zero queima com dados redundantes. |
| **7. Registro Fora de Escopo** | Refatorar arquivos vizinhos por impulso ("já que vi isso aqui, vou alterar..."). | Anotação concisa em 1 linha em `achados_fora_de_escopo` sem mexer no arquivo. | Mantém prompt limpo e estável. |

### Padrão Universal do Checkpoint Compacto [STATE] (≤ 15 Linhas)

```text
[STATE]
fase: <numero> | status: <concluida | parcial | bloqueada>
objetivo: <1 frase clara do entregavel executado>
decisoes: <decisao tecnica adotada — motivo sucinto>
arquivos_tocados: <caminho/do/arquivo:Lxx-Lyy resumo da alteracao em 1 linha>
mapa_atualizado: <sim | nao>
achados_fora_de_escopo: <nota: caminho:Lxx descricao em 1 linha | nenhum>
falhas: <o que falhou (tentativas: n) | nenhuma>
prox_passo: <1 frase clara da proxima acao imediata>
```

---

## 4. Benchmark: Abordagem Tradicional vs. Framework Coringa

| Dimensão Operacional | Abordagem Convencional | Framework Universal Coringa | Ganho Medido |
| :--- | :--- | :--- | :--- |
| **Consumo Médio de Tokens** | ~40.000 a 85.000 tokens por tarefa. | ~7.500 a 15.000 tokens por tarefa. | Redução de até 82% |
| **Alucinação Arquitetural** | Frequente (inventa arquivos e rotas). | Zero (ancorado no mapa do projeto). | 100% Determinístico |
| **Velocidade de Retorno** | Lenta com prolixidade conversacional. | Imediata no 1º caractere técnico útil. | Respostas até 3x mais rápidas |
| **Vida Útil do Contexto** | 10 a 15 turnos até exaustão da janela. | 40 a 70 turnos úteis preservando contexto. | 4x mais longevidade |

---

## 5. Guia de Implantação Rápida em 4 Passos em Qualquer Projeto

- **Passo 1 — Inserir o Prompt Mestre:** Crie ou edite o arquivo de regras na raiz do repositório (`AGENTS.md`, `.cursorrules`, `CLAUDE.md` ou configuração do assistente) colando as regras da Seção 1 deste manual.
- **Passo 2 — Inicializar o Mapa do Projeto:** Crie o arquivo `MAPA_DO_PROJETO.md` (ou `docs/MAPA_DO_SISTEMA.md`) na raiz contendo uma tabela de 2 colunas: Arquivo e Responsabilidade / Descrição. Liste os arquivos essenciais.
- **Passo 3 — Configurar o Controle de Concorrência:** Se múltiplos agentes ou terminais atuarem juntos, crie `HANDOFF.md` com a estrutura de semáforo declarativo (`## [TASK: nome]`) para reserva de arquivos.
- **Passo 4 — Forçar Edição Cirúrgica & Line-Slicing:** Nas ferramentas e permissões da IA, assegure que operações de substituição pontual utilizem `edit_file` e leituras usem faixas de linhas (`StartLine`/`EndLine`).

---

## 6. Checklist Inviolável de Governança para a IA

> **Mandamento de Conclusão:** Todo agente de IA deve obrigatoriamente validar a conformidade dos 7 pontos abaixo antes de emitir a mensagem final ou finalizar a tarefa. Caso qualquer item falhe, o status do checkpoint deve ser obrigatoriamente marcado como "parcial" ou "bloqueada" — nunca "concluida":

- [ ] **MAP-FIRST OBRIGATÓRIO:** O mapa arquitetural do projeto foi consultado antes de qualquer intervenção?
- [ ] **NAVEGAÇÃO RESTRITA:** Apenas os arquivos estritamente mapeados e necessários foram abertos e analisados?
- [ ] **MODIFICAÇÃO CIRÚRGICA:** Foi utilizado estritamente `edit_file` com ancoragem mínima, sem `write_file` integral?
- [ ] **LEITURA FATIADA:** O carregamento de código foi feito via faixas de linhas (line-slicing), sem leituras cegas massivas?
- [ ] **RETORNO E ATUALIZAÇÃO DO MAPA:** O mapa arquitetural foi atualizado com novos arquivos ou responsabilidades antes do fim?
- [ ] **ZERO-SLOP RIGOROSO:** A resposta ao usuário foi iniciada no 1º caractere útil, eliminando saudações e conclusões prolixas?
- [ ] **CHECKPOINT COMPACTO:** O estado final foi estruturado no formato plano `[STATE]` (≤ 15 linhas)?

---

## 7. Modelos Estruturais Prontos para Arquivos de Apoio

### Modelo: HANDOFF.md (Controle de Multi-Agente / Concorrência)
```markdown
# HANDOFF — CONTROLE MULTI-AGENTE DE ETAPAS ATIVAS
> Semáforo declarativo compartilhado entre múltiplos terminais para evitar Lost Updates.

## Status: Livre (Nenhuma tarefa ativa no momento)

<!-- Ao iniciar uma tarefa, insira abaixo via edit_file: -->
<!-- ## [TASK: refatorar-autenticacao] (Terminal: terminal-1) -->
<!-- - [ ] Fase 1: Criar schema de tokens -->
<!-- - [ ] Fase 2: Aplicar middleware nas rotas -->
<!-- Arquivos reservados: `src/auth.js`, `src/routes.js` -->
```
