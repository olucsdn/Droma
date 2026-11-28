<?php
/**
 * Parser Inteligente de Extratos Bancários (OFX e CSV) — Droma
 */
declare(strict_types=1);

final class ExtratoParser {
    /**
     * Mapeia termos comuns a categorias de despesa e receita
     */
    public static function sugerirCategoria(string $desc, array $cats, string $type): ?int {
        $d = mb_strtolower($desc);

        // 1. Tenta correspondência direta com o nome de qualquer categoria do usuário
        foreach ($cats as $c) {
            if ($c['type'] === $type) {
                $cNome = mb_strtolower(trim($c['name']));
                if ($cNome !== '' && (str_contains($d, $cNome) || str_contains($cNome, $d))) {
                    return (int)$c['id'];
                }
            }
        }

        // 2. Regras semânticas de palavras-chave bancárias
        $regras = [
            'alimentacao' => ['supermercado', 'mercado', 'restaurante', 'ifood', 'lanche', 'padaria', 'cafe', 'café', 'acougue', 'açougue', 'hortifruti', 'atacadão', 'atacadao', 'mcdonalds', 'burger king', 'subway', 'habibs'],
            'transporte'  => ['uber', '99', 'posto', 'gasolina', 'combustivel', 'combustível', 'estacionamento', 'pedagio', 'pedágio', 'sem parar', 'veloe', 'ipva', 'auto posto'],
            'moradia'     => ['aluguel', 'condominio', 'condomínio', 'luz', 'energia', 'enel', 'cpfl', 'agua', 'água', 'sabesp', 'gas', 'gás', 'internet', 'claro', 'vivo', 'tim', 'net ', 'iptu'],
            'saude'       => ['farmacia', 'farmácia', 'drogaria', 'raia', 'drogasil', 'pague menos', 'medico', 'médico', 'consulta', 'laboratorio', 'laboratório', 'hospital', 'remedio', 'remédio', 'odontologia', 'dentista', 'unimed', 'notredame', 'amil'],
            'salario'     => ['salario', 'salário', 'pro-labore', 'pró-labore', 'pagamento', 'pix recebido', 'rendimento', 'ted recebida', 'doc recebido', 'transferência recebida', 'remuneração', 'folha', 'ordenado'],
            'lazer'       => ['netflix', 'spotify', 'cinema', 'show', 'steam', 'playstation', 'xbox', 'viagem', 'hotel', 'airbnb', 'ingresso', 'livraria', 'amazon prime', 'disney'],
            'educacao'    => ['faculdade', 'universidade', 'escola', 'curso', 'udemy', 'alura', 'livro', 'mensalidade escolar', 'colegio', 'colégio'],
            'servicos'    => ['tarifa', 'tarifa bancaria', 'anuidade', 'iof', 'juros', 'manutencao', 'mensalidade']
        ];

        foreach ($regras as $tema => $termos) {
            foreach ($termos as $termo) {
                if (str_contains($d, $termo)) {
                    foreach ($cats as $c) {
                        if ($c['type'] === $type && str_contains(mb_strtolower($c['name']), $tema)) {
                            return (int)$c['id'];
                        }
                    }
                }
            }
        }

        // 3. Fallback inteligente: categoria genérica ('Outros', 'Geral', 'Diversos')
        foreach ($cats as $c) {
            if ($c['type'] === $type && in_array(mb_strtolower($c['name']), ['outros', 'geral', 'diversos', 'outras despesas', 'outras receitas'], true)) {
                return (int)$c['id'];
            }
        }

        // 4. Último fallback: primeira categoria do tipo correto
        foreach ($cats as $c) {
            if ($c['type'] === $type) {
                return (int)$c['id'];
            }
        }

        return null;
    }

    /**
     * Faz o parsing de conteúdo OFX com compatibilidade total (SGML sem fechamento e XML)
     */
    public static function parseOfx(string $conteudo, array $categorias, int $maxTransacoes = 2500): array {
        if (strlen($conteudo) > 5 * 1024 * 1024) {
            $conteudo = substr($conteudo, 0, 5 * 1024 * 1024);
        }

        // Converte encodings comuns de bancos brasileiros (ISO-8859-1 / Windows-1252) para UTF-8
        if (preg_match('/CHARSET:(?:1252|ISO-8859-1)/i', $conteudo) || !mb_check_encoding($conteudo, 'UTF-8')) {
            $conteudo = mb_convert_encoding($conteudo, 'UTF-8', 'ISO-8859-1, Windows-1252, UTF-8');
        }

        $linhas = [];

        // 1. Tenta encontrar blocos delimitados por <STMTTRN>...</STMTTRN> (OFX XML ou SGML com fechamento)
        preg_match_all('/<STMTTRN>(.*?)<\/STMTTRN>/is', $conteudo, $blocos);
        $listaBlocos = $blocos[1] ?? [];

        // 2. Se não houver tags de fechamento </STMTTRN> (OFX 1.x SGML padrão de Itaú, Caixa, Bradesco, BB, etc.)
        if (empty($listaBlocos)) {
            $partes = preg_split('/<STMTTRN>/i', $conteudo);
            if ($partes !== false && count($partes) > 1) {
                array_shift($partes); // Remove o cabeçalho antes do primeiro <STMTTRN>
                foreach ($partes as $p) {
                    // Corta o bloco se encontrar fim da lista ou tags de fechamento de conta
                    $posCorte = preg_match('/(?:<\/BANKTRANLIST>|<\/STMTRS>|<\/OFX>)/i', $p, $match, PREG_OFFSET_CAPTURE);
                    if ($posCorte && isset($match[0][1])) {
                        $p = substr($p, 0, (int)$match[0][1]);
                    }
                    $listaBlocos[] = $p;
                }
            }
        }

        $count = 0;
        foreach ($listaBlocos as $bloco) {
            // Valor da transação: suporta ponto e vírgula
            preg_match('/<TRNAMT>\s*([-+]?[0-9.,]+)/i', $bloco, $mValor);
            if (empty($mValor[1])) {
                continue;
            }
            $valRaw = str_replace(',', '.', trim($mValor[1]));
            $val = (float)$valRaw;
            if ($val == 0.0) {
                continue;
            }

            // Data da transação (captura YYYYMMDD mesmo com timezone, ex: 20261104120000[-03:EST])
            preg_match('/<DTPOSTED>\s*([0-9]{8})/i', $bloco, $mData);
            $rawDate = $mData[1] ?? date('Ymd');
            $dataIso = substr($rawDate, 0, 4) . '-' . substr($rawDate, 4, 2) . '-' . substr($rawDate, 6, 2);

            // Descrição (MEMO ou NAME)
            $desc = 'Lançamento bancário';
            if (preg_match('/<MEMO>(.*?)(?:<\/MEMO>|<|$|\r|\n)/i', $bloco, $mMemo) && trim($mMemo[1]) !== '') {
                $desc = trim($mMemo[1]);
            } elseif (preg_match('/<NAME>(.*?)(?:<\/NAME>|<|$|\r|\n)/i', $bloco, $mName) && trim($mName[1]) !== '') {
                $desc = trim($mName[1]);
            } elseif (preg_match('/<CHECKNUM>(.*?)(?:<\/CHECKNUM>|<|$|\r|\n)/i', $bloco, $mCheck) && trim($mCheck[1]) !== '') {
                $desc = 'Doc/Cheque ' . trim($mCheck[1]);
            }

            // Limpa tags e entidades HTML residuais
            $desc = html_entity_decode(strip_tags($desc), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $desc = preg_replace('/\s+/', ' ', trim($desc)) ?: 'Lançamento bancário';

            $tipo = $val > 0 ? 'income' : 'expense';
            $fitid = null;
            if (preg_match('/<FITID>(.*?)(?:<\/FITID>|<|$|\r|\n)/i', $bloco, $mFitid) && trim($mFitid[1]) !== '') {
                $fitid = trim($mFitid[1]);
            }

            $linhas[] = [
                'date'        => $dataIso,
                'description' => $desc,
                'amount'      => abs($val),
                'type'        => $tipo,
                'fitid'       => $fitid,
                'category_id' => self::sugerirCategoria($desc, $categorias, $tipo)
            ];

            $count++;
            if ($count >= $maxTransacoes) {
                break;
            }
        }

        return $linhas;
    }

    /**
     * Faz o parsing de conteúdo CSV com compatibilidade legada e baixo consumo de RAM
     */
    public static function parseCsv(string $conteudo, array $categorias, int $maxLinhas = 2500): array {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return [];
        }
        fwrite($stream, $conteudo);
        rewind($stream);
        $linhas = self::parseCsvStream($stream, $categorias, $maxLinhas);
        fclose($stream);
        return $linhas;
    }

    /**
     * Normaliza strings de data brasileiras e internacionais para YYYY-MM-DD
     */
    private static function normalizarData(string $dataStr): ?string {
        $dataStr = trim($dataStr);
        if ($dataStr === '') {
            return null;
        }

        // Se contiver letras fora de eventual timestamp ISO (ex: intervalos "17/08/2026 a 14/11/2026"), rejeita
        $semTimestampIso = preg_replace('/T\d{2}:\d{2}.*$/i', '', $dataStr);
        if (preg_match('/[a-zA-Z]/', $semTimestampIso)) {
            return null;
        }

        // Remove componente de horário estrito se houver (ex: '04/11/2026 14:30:00' ou '2026-11-04T12:00:00')
        $dataStr = preg_replace('/(?:T|\s+)\d{1,2}:\d{2}(?::\d{2})?.*$/i', '', $dataStr);
        $dataStr = trim($dataStr);

        // DD/MM/AAAA ou DD-MM-AAAA ou DD.MM.AAAA
        if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{4})$/', $dataStr, $m)) {
            $dia = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $mes = str_pad($m[2], 2, '0', STR_PAD_LEFT);
            $ano = $m[3];
            if (checkdate((int)$mes, (int)$dia, (int)$ano)) {
                return "{$ano}-{$mes}-{$dia}";
            }
        }

        // AAAA-MM-DD ou AAAA/MM/DD
        if (preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $dataStr, $m)) {
            $ano = $m[1];
            $mes = str_pad($m[2], 2, '0', STR_PAD_LEFT);
            $dia = str_pad($m[3], 2, '0', STR_PAD_LEFT);
            if (checkdate((int)$mes, (int)$dia, (int)$ano)) {
                return "{$ano}-{$mes}-{$dia}";
            }
        }

        // DD/MM/AA (ano com 2 dígitos)
        if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{2})$/', $dataStr, $m)) {
            $dia = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $mes = str_pad($m[2], 2, '0', STR_PAD_LEFT);
            $ano = '20' . $m[3];
            if (checkdate((int)$mes, (int)$dia, (int)$ano)) {
                return "{$ano}-{$mes}-{$dia}";
            }
        }

        return null;
    }

    /**
     * Normaliza strings de valor financeiro (R$, pontos de milhar, vírgulas decimais) para float
     */
    private static function normalizarValor(string $valStr): float {
        $valStr = trim(str_replace(['R$', 'r$', ' '], '', $valStr));
        if ($valStr === '') {
            return 0.0;
        }

        // Rejeita strings com barras ou formatos de data (ex: DD/MM/AAAA) para impedir que datas virem números milionários
        if (str_contains($valStr, '/') || self::normalizarData($valStr) !== null) {
            return 0.0;
        }

        // Trata valores com sinal ou parênteses contábeis (ex: "(150,00)" = -150.00)
        $negativo = false;
        if (str_starts_with($valStr, '(') && str_ends_with($valStr, ')')) {
            $negativo = true;
            $valStr = trim($valStr, '()');
        } elseif (str_starts_with($valStr, '-')) {
            $negativo = true;
            $valStr = ltrim($valStr, '-');
        } elseif (str_starts_with($valStr, '+')) {
            $valStr = ltrim($valStr, '+');
        }

        // Caso tenha ponto e vírgula (ex: 1.250,50 ou 1,250.50)
        if (str_contains($valStr, '.') && str_contains($valStr, ',')) {
            $posPonto = strrpos($valStr, '.');
            $posVirgula = strrpos($valStr, ',');
            if ($posVirgula > $posPonto) {
                // Padrão brasileiro: 1.250,50
                $valStr = str_replace('.', '', $valStr);
                $valStr = str_replace(',', '.', $valStr);
            } else {
                // Padrão americano: 1,250.50
                $valStr = str_replace(',', '', $valStr);
            }
        } elseif (str_contains($valStr, ',')) {
            // Apenas vírgula decimal: 1250,50
            $valStr = str_replace(',', '.', $valStr);
        }

        // Remove quaisquer caracteres residuais que não sejam dígitos ou ponto
        $valStr = preg_replace('/[^0-9.]/', '', $valStr) ?? '';
        
        // Se houver mais de um ponto residual ou não for numérico estrito, rejeita
        if ($valStr === '' || substr_count($valStr, '.') > 1 || !is_numeric($valStr)) {
            return 0.0;
        }

        $num = (float)$valStr;

        return $negativo ? -$num : $num;
    }

    /**
     * Faz o parsing de CSV em modo streaming via resource com detecção inteligente de colunas
     */
    public static function parseCsvStream($stream, array $categorias, int $maxLinhas = 2500): array {
        if (!is_resource($stream)) {
            return [];
        }

        // Lê amostra inicial para detectar separador e remover BOM UTF-8
        $amostra = fread($stream, 4096);
        if ($amostra === false || $amostra === '') {
            return [];
        }

        // Converte encoding se necessário
        if (!mb_check_encoding($amostra, 'UTF-8')) {
            $amostra = mb_convert_encoding($amostra, 'UTF-8', 'ISO-8859-1, Windows-1252, UTF-8');
        }

        // Remove Byte Order Mark (BOM UTF-8)
        $temBom = str_starts_with($amostra, "\xEF\xBB\xBF");
        rewind($stream);
        if ($temBom) {
            fseek($stream, 3);
        }

        // Detecta separador inspecionando as primeiras linhas da amostra
        $linhasAmostra = explode("\n", $amostra);
        $cntPV   = 0;
        $cntVirg = 0;
        $cntTab  = 0;
        $cntPipe = 0;
        $amostrasContadas = 0;
        foreach ($linhasAmostra as $lAmostra) {
            $lAmostra = trim($lAmostra);
            if ($lAmostra === '') {
                continue;
            }
            $cntPV   += substr_count($lAmostra, ';');
            $cntVirg += substr_count($lAmostra, ',');
            $cntTab  += substr_count($lAmostra, "\t");
            $cntPipe += substr_count($lAmostra, '|');
            $amostrasContadas++;
            if ($amostrasContadas >= 10) {
                break;
            }
        }

        $sep = ';';
        if ($cntTab > $cntPV && $cntTab > $cntVirg && $cntTab > $cntPipe) {
            $sep = "\t";
        } elseif ($cntVirg > $cntPV && $cntVirg > $cntTab && $cntVirg > $cntPipe) {
            $sep = ',';
        } elseif ($cntPipe > $cntPV && $cntPipe > $cntVirg && $cntPipe > $cntTab) {
            $sep = '|';
        }

        // Volta ao início (após BOM se houver) para processar linha a linha com fgetcsv
        rewind($stream);
        if ($temBom) {
            fseek($stream, 3);
        }

        $linhas = [];
        $headerFound = false;
        $count = 0;

        // Índices das colunas
        $idxData    = null;
        $idxHist    = null;
        $idxDesc    = null;
        $idxCat     = null;
        $idxValor   = null;
        $idxCredito = null;
        $idxDebito  = null;

        while (($cols = fgetcsv($stream, 4096, $sep, '"', "\\")) !== false) {
            if (empty($cols) || (count($cols) === 1 && ($cols[0] === null || trim((string)$cols[0]) === ''))) {
                continue;
            }

            // Normaliza cada coluna limpando espaços e garantindo UTF-8
            $cols = array_map(function($c) {
                $val = trim((string)$c);
                // Converte caso venha em ISO-8859-1
                if (!mb_check_encoding($val, 'UTF-8')) {
                    $val = mb_convert_encoding($val, 'UTF-8', 'ISO-8859-1, Windows-1252, UTF-8');
                }
                return $val;
            }, $cols);

            // Remove colunas vazias ao final da linha
            while (!empty($cols) && end($cols) === '') {
                array_pop($cols);
            }
            if (empty($cols)) {
                continue;
            }

            // 1. Detecção do cabeçalho nas linhas iniciais (mesmo após metadados de banco)
            if (!$headerFound) {
                $tData    = null;
                $tHist    = null;
                $tDesc    = null;
                $tCat     = null;
                $tValor   = null;
                $tCredito = null;
                $tDebito  = null;

                foreach ($cols as $i => $colName) {
                    $cLower = mb_strtolower(str_replace(["\xEF\xBB\xBF", '"', "'", "\r", "\n"], '', trim($colName)));
                    if ($cLower === '') {
                        continue;
                    }

                    // Data
                    if ($tData === null && (str_contains($cLower, 'data') || str_contains($cLower, 'date') || $cLower === 'dt' || str_contains($cLower, 'dt.'))) {
                        $tData = $i;
                    }
                    // Crédito separado
                    elseif ($tCredito === null && (str_contains($cLower, 'crédito') || str_contains($cLower, 'credito') || str_contains($cLower, 'entrada') || str_contains($cLower, 'receita'))) {
                        $tCredito = $i;
                    }
                    // Débito separado
                    elseif ($tDebito === null && (str_contains($cLower, 'débito') || str_contains($cLower, 'debito') || str_contains($cLower, 'saída') || str_contains($cLower, 'saida') || str_contains($cLower, 'despesa'))) {
                        $tDebito = $i;
                    }
                    // Valor único (ignora se contiver 'saldo' para não confundir com saldo residual da conta)
                    elseif ($tValor === null && !str_contains($cLower, 'saldo') && (str_contains($cLower, 'valor') || str_contains($cLower, 'amount') || $cLower === 'val' || $cLower === 'vlr')) {
                        $tValor = $i;
                    }
                    // Histórico bancário específico (ex: "Pix recebido", "Compra no débito", "Resgate")
                    elseif ($tHist === null && (str_contains($cLower, 'hist') || str_contains($cLower, 'lançamento') || str_contains($cLower, 'lancamento'))) {
                        $tHist = $i;
                    }
                    // Descrição / Estabelecimento / Memo / Detalhe
                    elseif ($tDesc === null && (str_contains($cLower, 'desc') || str_contains($cLower, 'memo') || str_contains($cLower, 'detalhe') || str_contains($cLower, 'estabelecimento') || str_contains($cLower, 'titulo') || str_contains($cLower, 'título') || str_contains($cLower, 'beneficiario') || str_contains($cLower, 'pagador') || str_contains($cLower, 'transação') || str_contains($cLower, 'transacao'))) {
                        $tDesc = $i;
                    }
                    // Categoria explícita
                    elseif ($tCat === null && (str_contains($cLower, 'categoria') || str_contains($cLower, 'category') || $cLower === 'cat')) {
                        $tCat = $i;
                    }
                }

                // Se encontrou coluna de data e coluna de valor (ou crédito/débito), é a linha de cabeçalho
                if ($tData !== null && ($tValor !== null || ($tCredito !== null && $tDebito !== null))) {
                    $idxData     = $tData;
                    $idxHist     = $tHist;
                    $idxDesc     = $tDesc;
                    $idxCat      = $tCat;
                    $idxValor    = $tValor;
                    $idxCredito  = $tCredito;
                    $idxDebito   = $tDebito;
                    $headerFound = true;
                    continue; // Pula linha de cabeçalho
                }

                // Se não é linha de cabeçalho, verifica se é uma linha de dados com data válida (arquivos sem cabeçalho)
                // Exige no mínimo 3 colunas para descartar linhas de metadados como "Período ;17/08/2026 a 14/11/2026"
                if (count($cols) >= 3) {
                    $dataCol = null;
                    foreach ($cols as $i => $val) {
                        if (self::normalizarData((string)$val) !== null) {
                            $dataCol = $i;
                            break;
                        }
                    }

                    if ($dataCol !== null) {
                        // Localiza coluna de valor (numérica e distinta da coluna de data)
                        $valorCol = null;
                        foreach ($cols as $i => $val) {
                            if ($i !== $dataCol && self::normalizarValor((string)$val) != 0.0) {
                                $valorCol = $i;
                                break;
                            }
                        }

                        if ($valorCol !== null) {
                            $idxData = $dataCol;
                            $idxValor = $valorCol;

                            // Localiza coluna de descrição (maior texto não-data e não-valor)
                            $descCol = null;
                            $maxLen = -1;
                            foreach ($cols as $i => $val) {
                                if ($i !== $idxData && $i !== $idxValor) {
                                    $len = mb_strlen(trim((string)$val));
                                    if ($len > $maxLen) {
                                        $maxLen = $len;
                                        $descCol = $i;
                                    }
                                }
                            }
                            $idxDesc = $descCol;
                            $headerFound = true;
                            // Linha já é de dados: segue para processamento abaixo
                        }
                    }
                }

                if (!$headerFound) {
                    // Linha de metadados preliminares (ex: nome do banco, agência, conta, etc.): ignora
                    continue;
                }
            }

            if ($idxData === null || !isset($cols[$idxData])) {
                continue;
            }

            // 2. Extração de Data
            $dataFinal = self::normalizarData((string)$cols[$idxData]);
            if ($dataFinal === null) {
                continue;
            }

            // 3. Extração de Valor e Tipo com salvaguarda anti-colisão
            $valNum = 0.0;
            if ($idxCredito !== null && $idxDebito !== null) {
                $vCred = self::normalizarValor((string)($cols[$idxCredito] ?? ''));
                $vDeb  = self::normalizarValor((string)($cols[$idxDebito] ?? ''));
                if (abs($vCred) > 0) {
                    $valNum = abs($vCred);
                } elseif (abs($vDeb) > 0) {
                    $valNum = -abs($vDeb);
                }
            } else {
                $colValorEfetiva = $idxValor;
                // Salvaguarda: se a coluna de valor for nula ou colidir com a de data, busca outra coluna
                if ($colValorEfetiva === null || $colValorEfetiva === $idxData) {
                    foreach ($cols as $i => $val) {
                        if ($i !== $idxData && self::normalizarValor((string)$val) != 0.0) {
                            $colValorEfetiva = $i;
                            break;
                        }
                    }
                }

                if ($colValorEfetiva === null || $colValorEfetiva === $idxData) {
                    continue;
                }

                $rawVal = $cols[$colValorEfetiva] ?? '0';
                $valNum = self::normalizarValor((string)$rawVal);
            }

            if ($valNum == 0.0) {
                continue;
            }

            $tipo = $valNum > 0 ? 'income' : 'expense';

            // 4. Extração de Descrição com suporte enriquecido a Histórico + Descrição
            $partesDesc = [];
            if ($idxHist !== null && isset($cols[$idxHist]) && trim((string)$cols[$idxHist]) !== '') {
                $histLimpo = html_entity_decode(strip_tags((string)$cols[$idxHist]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $histLimpo = preg_replace('/\s+/', ' ', trim($histLimpo));
                if ($histLimpo !== '' && self::normalizarData($histLimpo) === null) {
                    $partesDesc[] = $histLimpo;
                }
            }

            $descColEfetiva = $idxDesc;
            if ($descColEfetiva === null || $descColEfetiva === $idxData || $descColEfetiva === $idxValor) {
                foreach ($cols as $i => $val) {
                    if ($i !== $idxData && $i !== $idxValor && $i !== $idxHist && trim((string)$val) !== '') {
                        $descColEfetiva = $i;
                        break;
                    }
                }
            }

            if ($descColEfetiva !== null && isset($cols[$descColEfetiva])) {
                $descLimpo = html_entity_decode(strip_tags((string)$cols[$descColEfetiva]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $descLimpo = preg_replace('/\s+/', ' ', trim($descLimpo));
                if ($descLimpo !== '' && self::normalizarData($descLimpo) === null && (!isset($partesDesc[0]) || $descLimpo !== $partesDesc[0])) {
                    $partesDesc[] = $descLimpo;
                }
            }

            $descStr = !empty($partesDesc) ? implode(' - ', $partesDesc) : 'Lançamento bancário';

            $textoParaCategorizar = $descStr;
            if ($idxCat !== null && !empty($cols[$idxCat])) {
                $catOriginal = trim((string)$cols[$idxCat]);
                $textoParaCategorizar .= ' ' . $catOriginal;
            }

            $linhas[] = [
                'date'        => $dataFinal,
                'description' => $descStr,
                'amount'      => abs($valNum),
                'type'        => $tipo,
                'category_id' => self::sugerirCategoria($textoParaCategorizar, $categorias, $tipo)
            ];

            $count++;
            if ($count >= $maxLinhas) {
                break;
            }
        }

        return $linhas;
    }
}
