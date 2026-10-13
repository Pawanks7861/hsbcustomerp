<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;

class DocumentNumber
{
    /**
     * Next number for the current year. Call inside a database transaction.
     *
     * @param  class-string<Model>  $modelClass
     */
    public static function next(string $modelClass, string $column, string $prefix): string
    {
        $year = now()->format('Y');
        $latest = $modelClass::query()
            ->where($column, 'like', $prefix.'-'.$year.'-%')
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
