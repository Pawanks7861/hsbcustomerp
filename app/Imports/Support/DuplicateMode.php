<?php

namespace App\Imports\Support;

final class DuplicateMode
{
    public const CREATE_ONLY = 'create_only';

    public const SKIP_DUPLICATES = 'skip_duplicates';

    public const UPDATE_MATCHED = 'update_matched';

    public const UPSERT = 'upsert';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::CREATE_ONLY,
            self::SKIP_DUPLICATES,
            self::UPDATE_MATCHED,
            self::UPSERT,
        ];
    }
}
