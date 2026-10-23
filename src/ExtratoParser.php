<?php
/**
 * Parser Inteligente de Extratos Bancários (OFX e CSV) — Wert
 */
declare(strict_types=1);

final class ExtratoParser {
    /**
     * Mapeia termos comuns a categorias de despesa e receita
     */
    public static function sugerirCategoria(string $desc, array $cats, string $type): ?int {
        $d = mb_strtolower($desc);
        $regras = [
            'alimentacao' => ['supermercado', 'mercado', 'restaurante', 'ifood', 'lanche', 'padaria', 'cafe', 'acougue'],
            'transporte'  => ['uber', '99', 'posto', 'gasolina', 'combustivel', 'estacionamento', 'pedagio'],
            'moradia'     => ['aluguel', 'condominio', 'luz', 'energia', 'agua', 'gas', 'internet'],
            'saude'       => ['farmacia', 'drogaria', 'medico', 'consulta', 'laboratorio', 'hospital', 'remedio'],
            'salario'     => ['salario', 'pro-labore', 'pagamento', 'pix recebido', 'rendimento', 'ted recebida'],
            'lazer'       => ['netflix', 'spotify', 'cinema', 'show', 'steam', 'playstation', 'viagem']
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

        foreach ($cats as $c) {
            if ($c['type'] === $type) {
                return (int)$c['id'];
            }
        }
        return null;
    }

    /**
     * Faz o parsing de conteúdo OFX
     */
    public static function parseOfx(string $conteudo, array $categorias): array {
        $linhas = [];
        preg_match_all('/<STMTTRN>(.*?)<\/STMTTRN>/is', $conteudo, $blocos);
        if (!empty($blocos[1])) {
            foreach ($blocos[1] as $bloco) {
                preg_match('/<TRNAMT>([-+]?[0-9]*\.?[0-9]+)/i', $bloco, $mValor);
                preg_match('/<DTPOSTED>([0-9]{8})/i', $bloco, $mData);
                preg_match('/<(?:MEMO|NAME)>(.*?)(?:<\/|$|\r|\n)/i', $bloco, $mDesc);

                $val = (float)($mValor[1] ?? 0);
                if ($val == 0) continue;

                $rawDate = $mData[1] ?? date('Ymd');
                $dataIso = substr($rawDate, 0, 4) . '-' . substr($rawDate, 4, 2) . '-' . substr($rawDate, 6, 2);
                $tipo = $val > 0 ? 'income' : 'expense';
                $desc = trim($mDesc[1] ?? 'Lançamento bancário');

                $linhas[] = [
                    'date'        => $dataIso,
                    'description' => $desc,
                    'amount'      => abs($val),
                    'type'        => $tipo,
                    'category_id' => self::sugerirCategoria($desc, $categorias, $tipo)
                ];
            }
        }
        return $linhas;
    }

    /**
     * Faz o parsing de conteúdo CSV
     */
    public static function parseCsv(string $conteudo, array $categorias): array {
        $linhas = [];
        $rawLinhas = explode("\n", str_replace(["\r\n", "\r"], "\n", $conteudo));
        $rawLinhas = array_filter(array_map('trim', $rawLinhas));

        if (empty($rawLinhas)) {
            return [];
        }

        $primeira = reset($rawLinhas);
        $sep = (substr_count($primeira, ';') >= substr_count($primeira, ',')) ? ';' : ',';

        $inicio = 0;
        if (preg_match('/[a-zA-Z]/', $primeira)) {
            $inicio = 1;
        }

        $items = array_values($rawLinhas);
        for ($i = $inicio; $i < count($items); $i++) {
            $cols = str_getcsv($items[$i], $sep, '"', "\\");
            if (count($cols) < 2) continue;

            $dataStr = trim($cols[0]);
            $descStr = trim($cols[1] ?? 'Lançamento');
            $valStr  = trim($cols[2] ?? ($cols[1] ?? '0'));

            // Normaliza data
            $dataFinal = null;
            if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $dataStr, $m)) {
                $dataFinal = "{$m[3]}-{$m[2]}-{$m[1]}";
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataStr)) {
                $dataFinal = $dataStr;
            } else {
                continue;
            }

            // Normaliza valor
            $valStr = str_replace(['R$', ' '], '', $valStr);
            if (str_contains($valStr, ',') && str_contains($valStr, '.')) {
                $valStr = str_replace('.', '', $valStr);
                $valStr = str_replace(',', '.', $valStr);
            } elseif (str_contains($valStr, ',')) {
                $valStr = str_replace(',', '.', $valStr);
            }
            $valNum = (float)$valStr;
            if ($valNum == 0) continue;

            $tipo = $valNum > 0 ? 'income' : 'expense';

            $linhas[] = [
                'date'        => $dataFinal,
                'description' => $descStr,
                'amount'      => abs($valNum),
                'type'        => $tipo,
                'category_id' => self::sugerirCategoria($descStr, $categorias, $tipo)
            ];
        }

        return $linhas;
    }
}
