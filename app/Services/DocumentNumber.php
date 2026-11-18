<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class DocumentNumber
{
    /**
     * Next number for the current year. Call inside a database transaction.
     *
     * Length-then-lexicographic ordering keeps sequences unique after 999, when
     * the numeric suffix grows wider than the original 3-digit zero padding.
     *
     * @param  class-string<Model>  $modelClass
     */
    public static function next(string $modelClass, string $column, string $prefix): string
    {
        $year = now()->format('Y');
        $grammar = DB::connection()->getQueryGrammar();
        $wrapped = $grammar->wrap($column);

        $latest = $modelClass::query()
            ->where($column, 'like', $prefix.'-'.$year.'-%')
            ->orderByRaw('LENGTH('.$wrapped.') DESC')
            ->orderByDesc($column)
            ->lockForUpdate()
            ->value($column);

        $sequence = 1;

        if (is_string($latest) && preg_match('/(\d+)$/', $latest, $matches) === 1) {
            $sequence = ((int) $matches[1]) + 1;
        }

        return sprintf('%s-%s-%03d', $prefix, $year, $sequence);
    }
}
