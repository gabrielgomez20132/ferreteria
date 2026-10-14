<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * Lee planillas XLSX / XLS / ODS / CSV y devuelve filas indexadas por letra de columna.
 */
class SpreadsheetReader
{
    /**
     * @return array<int, array<string, mixed>> filas indexadas por número de fila (1-based)
     */
    public static function rows(string $path, ?int $limit = null): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);

        if ($reader instanceof Csv) {
            $reader->setInputEncoding(Csv::GUESS_ENCODING);
            $reader->setFallbackEncoding('CP1252');
        }

        if ($limit) {
            $reader->setReadFilter(new class($limit) implements IReadFilter
            {
                public function __construct(private int $limit) {}

                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row <= $this->limit;
                }
            });
        }

        $sheet = $reader->load($path)->getActiveSheet();
        $data = $sheet->toArray(null, true, false, true);

        $rows = [];
        foreach ($data as $rowNumber => $cells) {
            $clean = [];
            $hasValue = false;
            foreach ($cells as $col => $value) {
                $value = self::normalize($value);
                if ($value !== null && $value !== '') {
                    $hasValue = true;
                }
                $clean[$col] = $value;
            }
            if ($hasValue) {
                $rows[$rowNumber] = $clean;
            }
        }

        return $rows;
    }

    public static function normalize(mixed $value): mixed
    {
        if (is_string($value)) {
            $value = trim(preg_replace('/\s+/u', ' ', $value));

            return $value === '' ? null : $value;
        }

        return $value;
    }

    /** Texto para códigos: 12345.0 → "12345" */
    public static function text(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_float($value) && floor($value) == $value && abs($value) < 1e15) {
            return number_format($value, 0, '', '');
        }

        return trim((string) $value);
    }

    /**
     * Detecta la fila de encabezado: la primera fila (entre las 25 primeras) donde se reconocen
     * más columnas conocidas (código, descripción, precio...). Las listas suelen traer títulos arriba.
     */
    public static function detectHeaderRow(array $rows): int
    {
        $best = 1;
        $bestScore = 0;
        foreach (array_slice($rows, 0, 25, true) as $n => $cells) {
            $texts = array_filter($cells, fn ($v) => is_string($v) && mb_strlen($v) <= 40);
            $score = count(self::guessMapping($texts));
            if ($score > $bestScore && count($texts) >= 2) {
                $best = $n;
                $bestScore = $score;
            }
        }

        return $bestScore >= 2 ? $best : 1;
    }

    /** Detecta columnas por nombre de encabezado */
    public static function guessMapping(array $headerRow): array
    {
        // El orden importa: "Código de barras" debe reconocerse antes que "Código"
        $patterns = [
            'barcode' => '/(ean|barra|gtin|upc)/iu',
            'code' => '/^(c[oó]d(igo|\\.)?|art(i|í)culo|ref(erencia)?|sku|item)/iu',
            'description' => '/(desc|detalle|producto|nombre)/iu',
            'price' => '/(precio|p\\.? ?lista|lista|neto|costo|importe|valor|\\$)/iu',
            'brand' => '/(marca)/iu',
            'pack' => '/(bulto|pack|x ?caja|unid(ades)? ?x|cant\\.? ?x)/iu',
        ];
        $mapping = [];
        foreach ($headerRow as $col => $value) {
            $value = (string) $value;
            foreach ($patterns as $field => $regex) {
                if (! isset($mapping[$field]) && preg_match($regex, $value)) {
                    $mapping[$field] = $col;
                    break;
                }
            }
        }

        return $mapping;
    }
}
