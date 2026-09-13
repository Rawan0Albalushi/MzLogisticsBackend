<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

final class ListFilters
{
    /**
     * @param  array<int, string>  $columns
     * @param  array<string, list<string>>  $relations
     */
    public static function search(Builder $query, mixed $term, array $columns, array $relations = []): Builder
    {
        $search = trim((string) $term);
        if ($search === '') {
            return $query;
        }

        return $query->where(function (Builder $builder) use ($search, $columns, $relations) {
            foreach ($columns as $index => $column) {
                if ($index === 0) {
                    $builder->where($column, 'like', "%{$search}%");
                } else {
                    $builder->orWhere($column, 'like', "%{$search}%");
                }
            }

            foreach ($relations as $relation => $relationColumns) {
                $builder->orWhereHas($relation, function (Builder $related) use ($search, $relationColumns) {
                    $related->where(function (Builder $inner) use ($search, $relationColumns) {
                        foreach ($relationColumns as $index => $column) {
                            if ($index === 0) {
                                $inner->where($column, 'like', "%{$search}%");
                            } else {
                                $inner->orWhere($column, 'like', "%{$search}%");
                            }
                        }
                    });
                });
            }
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public static function dateRange(Builder $query, array $filters, string $column = 'created_at'): Builder
    {
        $from = $filters['date_from'] ?? $filters['from'] ?? null;
        $to = $filters['date_to'] ?? $filters['to'] ?? null;

        if (filled($from)) {
            $query->whereDate($column, '>=', $from);
        }

        if (filled($to)) {
            $query->whereDate($column, '<=', $to);
        }

        return $query;
    }

    public static function city(Builder $query, mixed $city, array $columns = ['city']): Builder
    {
        $value = trim((string) $city);
        if ($value === '') {
            return $query;
        }

        return $query->where(function (Builder $builder) use ($value, $columns) {
            foreach ($columns as $index => $column) {
                if ($index === 0) {
                    $builder->where($column, 'like', "%{$value}%");
                } else {
                    $builder->orWhere($column, 'like', "%{$value}%");
                }
            }
        });
    }
}
