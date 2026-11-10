<?php

namespace App\Support;

use App\Models\FinanceArchive;
use Illuminate\Database\Eloquent\Builder;

class FinanceArchiveFilter
{
    public static function apply(Builder $query, string $subjectType, ?string $visibility, string $idColumn = 'id'): void
    {
        $visibility = $visibility ?: 'active';

        if ($visibility === 'all') {
            return;
        }

        $archivedIds = FinanceArchive::archivedIds($subjectType);

        if ($visibility === 'archived') {
            $query->whereIn($idColumn, $archivedIds ?: [-1]);

            return;
        }

        if ($archivedIds !== []) {
            $query->whereNotIn($idColumn, $archivedIds);
        }
    }
}
