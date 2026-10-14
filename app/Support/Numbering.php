<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Numeración correlativa de comprobantes (debe llamarse dentro de una transacción).
 */
class Numbering
{
    public static function next(string $table, array $where): int
    {
        $q = DB::table($table)->lockForUpdate();
        foreach ($where as $col => $val) {
            $q->where($col, $val);
        }

        return ((int) $q->max('number')) + 1;
    }
}
