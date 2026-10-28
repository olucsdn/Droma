<?php
/**
 * Cliente de Integração com o Google Gemini e Assistente Financeiro Droma
 */
final class ClienteIA {
    private const DEFAULT_MODEL = 'gemini-3.6-flash';
    public const QUOTA_PER_CYCLE = 1;
    public const DAILY_LIMIT = 1; // Mantido para compatibilidade retroativa

    private string $provedorUtilizado = '';

    public function getProvedorUtilizado(): string {
        return $this->provedorUtilizado;
    }

    public function analisar(array $dados): string {
        $lastError = 'Nenhum provedor de IA configurado.';

        // 1. Provedor Principal: Hugging Face Router
        $hfToken = $this->tokenHF();
        if ($hfToken !== '') {
            try {
                $hfRes = $this->analisarHuggingFace($dados, $hfToken);
                if ($hfRes !== null && trim($hfRes) !== '') {
                    return $hfRes;
                }
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                error_log("[Assistant AI] Falha no Hugging Face (acionando fallback Gemini): " . $e->getMessage());
            }
        }

        // 2. Provedor Secundário (Fallback): Google Gemini (com fallback entre modelos)
        $geminiKey = $this->chave();
        if ($geminiKey !== '') {
            try {
                return $this->analisarGemini($dados, $geminiKey);
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                error_log("[Assistant AI] Falha no Gemini: " . $e->getMessage());
            }
        }

        throw new RuntimeException($lastError);
    }

    /**
     * Executa análise via Google Gemini com tolerância a falhas
     */
    private function analisarGemini(array $dados, string $key): string {
        $preferredModel = $this->obterModelo();
        $modelCandidates = array_unique(array_filter([
            $preferredModel,
            'gemini-3.6-flash',
            'gemini-3.5-flash',
            'gemini-3.7-flash',
            'gemini-3.5-flash-lite'
        ]));

        $payload = [
            'systemInstruction' => ['parts' => [['text' =>
                'Você é o assistente financeiro do Droma. Responda em português do Brasil de forma acolhedora, objetiva e sem qualquer julgamento moral. '
                . 'Analise os números fornecidos e dê exatamente 3 sugestões práticas e realistas para ajudar o usuário a economizar ou planejar melhor. '
                . 'Use somente os números enviados; não invente dados e não recomende investimentos específicos ou arriscados.'
            ]]],
            'contents' => [[
                'role'  => 'user',
                'parts' => [['text' => 'Dados financeiros consolidados: ' . json_encode($dados, JSON_UNESCAPED_UNICODE)]],
            ]],
            'generationConfig' => [
                'maxOutputTokens' => 3000,
                'temperature' => 0.4,
                'thinkingConfig' => ['thinkingBudget' => 512]
            ],
        ];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $lastError = 'O assistente está indisponível no momento.';
        foreach ($modelCandidates as $model) {
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($model) . ':generateContent';

            $c = curl_init($url);
            curl_setopt_array($c, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-goog-api-key: ' . $key],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 20,
            ]);
            $raw  = curl_exec($c);
            $code = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);

            if ($raw === false) {
                continue;
            }
            if ($code === 429) {
                throw new RuntimeException('O limite de requisições do provedor de IA foi atingido. Tente novamente mais tarde.');
            }
            if ($code === 200) {
                $decoded = json_decode($raw, true);
                $parts = $decoded['candidates'][0]['content']['parts'] ?? [];
                $textParts = [];
                foreach ($parts as $p) {
                    if (isset($p['text']) && is_string($p['text'])) {
                        $textParts[] = $p['text'];
                    }
                }
                $text = implode("\n", $textParts);
                if (trim($text) !== '') {
                    $this->provedorUtilizado = 'Gemini (' . $model . ')';
                    return mb_substr(trim($text), 0, 5000);
                }
            }
            $lastError = "O assistente Gemini retornou status HTTP $code para o modelo $model.";
        }

        throw new RuntimeException($lastError);
    }

    /**
     * Executa análise via Hugging Face Router (OpenAI-compatible)
     */
    private function analisarHuggingFace(array $dados, string $token): ?string {
        $preferredModel = $this->obterModeloHF();
        $modelCandidates = array_unique(array_filter([
            $preferredModel,
            'meta-llama/Llama-3.1-8B-Instruct',
            'Qwen/Qwen2.5-72B-Instruct',
            'meta-llama/Llama-3.2-3B-Instruct',
            'deepseek-ai/DeepSeek-V4.1-Flash'
        ]));

        $lastError = 'Hugging Face indisponível no momento.';
        foreach ($modelCandidates as $model) {
            $url = 'https://router.huggingface.co/v1/chat/completions';
            $payload = [
                'model' => $model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Você é o assistente financeiro do Droma. Responda em português do Brasil de forma acolhedora, objetiva e sem qualquer julgamento moral. '
                            . 'Analise os números fornecidos e dê exatamente 3 sugestões práticas e realistas para ajudar o usuário a economizar ou planejar melhor. '
                            . 'Use somente os números enviados; não invente dados e não recomende investimentos específicos ou arriscados.'
                    ],
                    [
                        'role' => 'user',
                        'content' => 'Dados financeiros consolidados: ' . json_encode($dados, JSON_UNESCAPED_UNICODE)
                    ]
                ],
                'max_tokens' => 1500,
                'temperature' => 0.4
            ];

            $c = curl_init($url);
            curl_setopt_array($c, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER     => [
                    "Authorization: Bearer $token",
                    'Content-Type: application/json'
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 25,
            ]);
            $raw  = curl_exec($c);
            $code = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);

            if ($raw === false) {
                continue;
            }

            if ($code === 200 && is_string($raw)) {
                $data = json_decode($raw, true);
                $content = $data['choices'][0]['message']['content'] ?? '';
                if (is_string($content) && trim($content) !== '') {
                    $this->provedorUtilizado = 'Hugging Face (' . basename($model) . ')';
                    return mb_substr(trim($content), 0, 5000);
                }
            }

            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            $errDetail = $decoded['error']['message'] ?? ($decoded['error'] ?? "Status HTTP $code");
            $lastError = "Hugging Face ($model): $errDetail";
        }

        throw new RuntimeException($lastError);
    }

    /**
     * Retorna informações sobre o ciclo quinzenal e o consumo de IA do usuário:
     * - Ciclo 1 (Dias 01 a 14): Planejamento e Fechamento do Mês
     * - Ciclo 2 (Dia 15 ao fim do mês): Check-up Quinzenal de Ajuste de Rota
     */
    public function getUsage(PDO $pdo, int $userId): array {
        $diaAtual = (int)date('j');
        $anoMes   = date('Y-m');

        if ($diaAtual < 15) {
            $cicloNome        = 'Início do Mês (Planejamento)';
            $startDate        = "{$anoMes}-01";
            $endDate          = "{$anoMes}-14";
            $proximaLiberacao = date('15/m/Y');
        } else {
            $cicloNome        = 'Meio do Mês (Check-up Quinzenal)';
            $startDate        = "{$anoMes}-15";
            $endDate          = date('Y-m-t');
            $proximaLiberacao = date('01/m/Y', strtotime('first day of next month'));
        }

        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(requests), 0) 
            FROM ai_daily_usage 
            WHERE user_id = ? AND usage_date BETWEEN ? AND ?
        ");
        $stmt->execute([$userId, $startDate, $endDate]);
        $requests = (int)$stmt->fetchColumn();

        $remaining = max(0, self::QUOTA_PER_CYCLE - $requests);
        $allowed   = ($remaining > 0);

        return [
            'requests'          => $requests,
            'limit'             => self::QUOTA_PER_CYCLE,
            'remaining'         => $remaining,
            'allowed'           => $allowed,
            'ciclo_nome'        => $cicloNome,
            'proxima_liberacao' => $proximaLiberacao
        ];
    }

    /**
     * Mantém compatibilidade retroativa com chamadas legadas
     */
    public function getDailyUsage(PDO $pdo, int $userId): array {
        return $this->getUsage($pdo, $userId);
    }

    /**
     * Incrementa o contador de requisições diárias
     */
    public function recordUsage(PDO $pdo, int $userId): void {
        $today = date('Y-m-d');
        $stmt = $pdo->prepare("
            INSERT INTO ai_daily_usage (user_id, usage_date, requests)
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE requests = requests + 1
        ");
        $stmt->execute([$userId, $today]);
    }

    /**
     * Gera análises e recomendações heurísticas locais quando a IA estiver offline ou limite atingido
     */
    public function gerarAnaliseLocal(array $dados): string {
        $receitas = (float) ($dados['receitas'] ?? 0);
        $despesas = (float) ($dados['despesas'] ?? 0);
        $saldo = $receitas - $despesas;
        $taxaPoupanca = $receitas > 0 ? round(($saldo / $receitas) * 100, 1) : 0;

        $linhas = [];
        $linhas[] = "💡 **Diagnóstico Financeiro Automatizado (Droma Analytics)**\n";

        if ($saldo > 0) {
            $linhas[] = "• **Superávit Operacional:** Parabéns! Você fechou o período com saldo positivo de R$ " . number_format($saldo, 2, ',', '.') . " (taxa de poupança de {$taxaPoupanca}% das suas receitas).";
            if ($taxaPoupanca >= 20) {
                $linhas[] = "• **Excelente Disciplina:** Manter uma taxa de poupança acima de 20% é o padrão ideal para construir reservas de emergência e atingir suas metas de médio prazo.";
            } else {
                $linhas[] = "• **Oportunidade de Otimização:** Experimente destinar gradualmente ao menos 15% a 20% das receitas para seus objetivos financeiros cadastrados na aba Metas.";
            }
        } elseif ($saldo < 0) {
            $linhas[] = "• **Alerta de Déficit:** As despesas superaram as receitas em R$ " . number_format(abs($saldo), 2, ',', '.') . " no período. Recomenda-se revisar gastos imediatos.";
            $linhas[] = "• **Ajuste de Custos:** Verifique categorias não essenciais ou compras parceladas no cartão para conter a pressão sobre o fluxo de caixa.";
        } else {
            $linhas[] = "• **Equilíbrio Financeiro:** Suas receitas e despesas estão exatamente empatadas. O foco para os próximos meses deve ser criar uma margem de segurança.";
        }

        // Análise de maiores gastos
        if (!empty($dados['maiores_despesas']) && is_array($dados['maiores_despesas'])) {
            $top = $dados['maiores_despesas'][0];
            $catNome = $top['categoria'] ?? 'Outros';
            $catTotal = (float) ($top['total'] ?? 0);
            $percCat = $despesas > 0 ? round(($catTotal / $despesas) * 100, 1) : 0;
            $linhas[] = "• **Maior Concentração:** A categoria **{$catNome}** representou {$percCat}% (R$ " . number_format($catTotal, 2, ',', '.') . ") do seu total de despesas. Acompanhar essa área trará o maior impacto na sua economia.";
        }

        return implode("\n\n", $linhas);
    }

    private function obterModelo(): string {
        $envModel = getenv('GEMINI_MODEL') ?: ($_ENV['GEMINI_MODEL'] ?? '');
        if ($envModel !== '') {
            return trim($envModel);
        }

        $candidatos = [
            'C:/laragon/segredos/droma.env',
            'C:/laragon/segredos/wert.env',
            'C:/xampp/segredos/droma.env',
            'C:/xampp/segredos/wert.env',
            dirname(__DIR__, 2) . '/segredos/droma.env',
            dirname(__DIR__, 2) . '/segredos/wert.env',
            dirname(__DIR__) . '/.env'
        ];

        foreach ($candidatos as $f) {
            if (is_readable($f)) {
                foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
                    $l = trim($l);
                    if (str_starts_with($l, 'GEMINI_MODEL=')) {
                        return trim(substr($l, 13), " \t\"'");
                    }
                }
            }
        }

        return self::DEFAULT_MODEL;
    }

    private function chave(): string {
        $envKey = getenv('GEMINI_API_KEY') ?: ($_ENV['GEMINI_API_KEY'] ?? '');
        if ($envKey !== '') {
            return trim($envKey);
        }

        $candidatos = [
            'C:/laragon/segredos/droma.env',
            'C:/laragon/segredos/wert.env',
            'C:/xampp/segredos/droma.env',
            'C:/xampp/segredos/wert.env',
            dirname(__DIR__, 2) . '/segredos/droma.env',
            dirname(__DIR__, 2) . '/segredos/wert.env',
            dirname(__DIR__) . '/.env'
        ];

        foreach ($candidatos as $f) {
            if (is_readable($f)) {
                foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
                    $l = trim($l);
                    if (str_starts_with($l, 'GEMINI_API_KEY=')) {
                        return trim(substr($l, 15), " \t\"'");
                    }
                }
            }
        }

        return '';
    }

    private function tokenHF(): string {
        $envKey = getenv('HF_TOKEN') ?: ($_ENV['HF_TOKEN'] ?? '');
        if ($envKey !== '') {
            return trim($envKey);
        }

        $candidatos = [
            'C:/laragon/segredos/droma.env',
            'C:/laragon/segredos/wert.env',
            'C:/xampp/segredos/droma.env',
            'C:/xampp/segredos/wert.env',
            dirname(__DIR__, 2) . '/segredos/droma.env',
            dirname(__DIR__, 2) . '/segredos/wert.env',
            dirname(__DIR__) . '/.env'
        ];

        foreach ($candidatos as $f) {
            if (is_readable($f)) {
                foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
                    $l = trim($l);
                    if (str_starts_with($l, 'HF_TOKEN=')) {
                        return trim(substr($l, 9), " \t\"'");
                    }
                }
            }
        }

        return '';
    }

    private function obterModeloHF(): string {
        $envModel = getenv('HF_MODEL') ?: ($_ENV['HF_MODEL'] ?? '');
        if ($envModel !== '') {
            return trim($envModel);
        }

        $candidatos = [
            'C:/laragon/segredos/droma.env',
            'C:/laragon/segredos/wert.env',
            'C:/xampp/segredos/droma.env',
            'C:/xampp/segredos/wert.env',
            dirname(__DIR__, 2) . '/segredos/droma.env',
            dirname(__DIR__, 2) . '/segredos/wert.env',
            dirname(__DIR__) . '/.env'
        ];

        foreach ($candidatos as $f) {
            if (is_readable($f)) {
                foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
                    $l = trim($l);
                    if (str_starts_with($l, 'HF_MODEL=')) {
                        return trim(substr($l, 9), " \t\"'");
                    }
                }
            }
        }

        return 'meta-llama/Llama-3.1-8B-Instruct';
    }
}
