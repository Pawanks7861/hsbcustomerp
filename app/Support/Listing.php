<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Shared server-side search + pagination for ERP listing tables.
 *
 * Searchable columns are always passed in by the controller (never taken
 * from the request), so users can only search fields that listing shows.
 */
final class Listing
{
    /** @var list<int> */
    public const PER_PAGE_OPTIONS = [10, 30, 50, 100];

    public const DEFAULT_PER_PAGE = 30;

    public const MAX_SEARCH_LENGTH = 100;

    private const MAX_WORDS = 6;

    private const DATE_FORMATS = ['d/m/Y', 'd-m-Y', 'd.m.Y', 'j/n/Y', 'j-n-Y', 'd M Y', 'j M Y', 'd M, Y', 'M d, Y', 'd F Y', 'j F Y'];

    public static function search(Request $request, string $param = 'search'): string
    {
        $value = $request->query($param, $request->input($param, ''));
        if (! is_string($value)) {
            return '';
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', SafeInput::plainText($value)));

        return mb_substr($value, 0, self::MAX_SEARCH_LENGTH);
    }

    public static function perPage(Request $request, string $param = 'per_page'): int
    {
        $value = $request->query($param);
        $value = is_numeric($value) ? (int) $value : self::DEFAULT_PER_PAGE;

        return in_array($value, self::PER_PAGE_OPTIONS, true) ? $value : self::DEFAULT_PER_PAGE;
    }

    /**
     * @return array{search: string, per_page: int}
     */
    public static function filters(Request $request): array
    {
        return ['search' => self::search($request), 'per_page' => self::perPage($request)];
    }

    /**
     * Restrict a query to rows where every search word matches at least one
     * allowlisted column. Columns use dot notation for relations, e.g.
     * "vendor.name" or "items.material.name".
     *
     * @template TBuilder of EloquentBuilder
     *
     * @param  TBuilder  $query
     * @param  list<string>  $columns
     * @return TBuilder
     */
    public static function applySearch(EloquentBuilder $query, string $term, array $columns): EloquentBuilder
    {
        $words = self::words($term);
        if ($words === [] || $columns === []) {
            return $query;
        }

        $groups = [];
        foreach ($columns as $column) {
            $position = strrpos($column, '.');
            $relation = $position === false ? '' : substr($column, 0, $position);
            $groups[$relation][] = $position === false ? $column : substr($column, $position + 1);
        }

        foreach ($words as $word) {
            $patterns = array_map(fn (string $variant) => '%'.self::escapeLike($variant).'%', self::variants($word));

            $query->where(function ($any) use ($groups, $patterns): void {
                foreach ($groups as $relation => $fields) {
                    $matcher = function ($inner) use ($fields, $patterns): void {
                        $inner->where(function ($or) use ($fields, $patterns): void {
                            foreach ($fields as $field) {
                                $wrapped = $or->getQuery()->getGrammar()->wrap($or->qualifyColumn($field));
                                foreach ($patterns as $pattern) {
                                    $or->orWhereRaw("{$wrapped} like ? escape '!'", [$pattern]);
                                }
                            }
                        });
                    };

                    if ($relation === '') {
                        $any->orWhere($matcher);
                    } else {
                        $any->orWhereHas($relation, $matcher);
                    }
                }
            });
        }

        return $query;
    }

    public static function paginate(EloquentBuilder $query, Request $request, string $pageName = 'page', string $perPageParam = 'per_page'): LengthAwarePaginator
    {
        $perPage = self::perPage($request, $perPageParam);
        $page = max(1, (int) $request->query($pageName, 1));

        $paginator = $query->paginate($perPage, ['*'], $pageName, $page);
        if ($paginator->isEmpty() && $page > 1 && $paginator->total() > 0) {
            $paginator = (clone $query)->paginate($perPage, ['*'], $pageName, $paginator->lastPage());
        }

        return $paginator->withQueryString();
    }

    /**
     * Search and paginate rows that are computed in PHP (ledgers with running
     * balances, stock balances, report rows). Only the given keys are searched.
     *
     * @param  Collection<int, array<string, mixed>>|array<int, array<string, mixed>>  $rows
     * @param  list<string>  $keys
     */
    public static function paginateRows(Collection|array $rows, Request $request, array $keys, string $searchParam = 'search', string $pageName = 'page', string $perPageParam = 'per_page'): LengthAwarePaginator
    {
        $rows = self::filterRows($rows, self::search($request, $searchParam), $keys);

        $perPage = self::perPage($request, $perPageParam);
        $total = $rows->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) $request->query($pageName, 1)), $lastPage);

        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $total,
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => $pageName],
        );

        return $paginator->withQueryString();
    }

    /**
     * Keep only rows where every search word appears in one of the given keys.
     *
     * @param  Collection<int, array<string, mixed>>|array<int, array<string, mixed>>  $rows
     * @param  list<string>  $keys
     * @return Collection<int, array<string, mixed>>
     */
    public static function filterRows(Collection|array $rows, string $term, array $keys): Collection
    {
        $rows = collect($rows)->values();
        $words = self::words($term);
        if ($words === []) {
            return $rows;
        }

        return $rows->filter(function ($row) use ($keys, $words): bool {
            $haystack = mb_strtolower(implode(' | ', array_map(
                fn (string $key) => self::stringify(data_get($row, $key)),
                $keys,
            )));

            foreach ($words as $word) {
                $found = false;
                foreach (self::variants($word) as $variant) {
                    if (str_contains($haystack, mb_strtolower($variant))) {
                        $found = true;
                        break;
                    }
                }
                if (! $found) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    /**
     * @return list<string>
     */
    private static function words(string $term): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        $date = self::dateVariant($term);
        if ($date !== null) {
            return [$date];
        }

        return array_slice(array_values(array_unique(array_filter(explode(' ', $term), fn ($w) => $w !== ''))), 0, self::MAX_WORDS);
    }

    /**
     * @return list<string>
     */
    private static function variants(string $word): array
    {
        $variants = [$word];

        $numeric = str_replace([',', '₹', 'Rs.', 'Rs'], '', $word);
        if ($numeric !== $word && $numeric !== '' && is_numeric($numeric)) {
            $variants[] = $numeric;
        }

        $date = self::dateVariant($word);
        if ($date !== null) {
            $variants[] = $date;
        }

        return array_values(array_unique($variants));
    }

    private static function dateVariant(string $value): ?string
    {
        if (preg_match('/\d/', $value) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return null;
        }

        // Single words cannot match the spaced formats, so skip Carbon's costly failures.
        if (! str_contains($value, ' ') && preg_match('/^\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{4}$/', $value) !== 1) {
            return null;
        }

        foreach (self::DATE_FORMATS as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);
                if ($date !== false && $date->format($format) === $value) {
                    return $date->toDateString();
                }
            } catch (Throwable) {
            }
        }

        return null;
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    private static function stringify(mixed $value): string
    {
        if (is_array($value)) {
            return implode(' ', array_map(fn ($item) => self::stringify($item), $value));
        }
        if (is_bool($value)) {
            return $value ? 'yes active' : 'no inactive';
        }
        if ($value === null) {
            return '';
        }

        $text = (string) $value;

        return str_contains($text, '_') ? $text.' '.str_replace('_', ' ', $text) : $text;
    }
}
