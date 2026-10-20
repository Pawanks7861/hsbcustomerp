<?php

namespace App\Imports\Support;

final class RowOutcome
{
    public const VALID = 'valid';

    public const INVALID = 'invalid';

    public const DUPLICATE = 'duplicate';

    public const SKIP = 'skip';

    /**
     * @param  list<string>  $errors
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly int $rowNumber,
        public readonly string $status,
        public readonly array $data = [],
        public readonly array $errors = [],
        public readonly ?string $matchKey = null,
        public readonly ?int $existingId = null,
        public readonly ?string $action = null,
    ) {}

    public function isImportable(): bool
    {
        return in_array($this->status, [self::VALID, self::DUPLICATE], true)
            && in_array($this->action, ['create', 'update'], true);
    }
}
