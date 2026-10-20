<?php

namespace App\Imports\Support;

final class ColumnDefinition
{
    /**
     * @param  list<string>  $aliases
     * @param  list<string>|null  $allowed
     */
    public function __construct(
        public readonly string $key,
        public readonly string $heading,
        public readonly bool $required = false,
        public readonly string $type = 'string',
        public readonly array $aliases = [],
        public readonly ?int $max = null,
        public readonly ?array $allowed = null,
        public readonly ?string $help = null,
    ) {}
}
