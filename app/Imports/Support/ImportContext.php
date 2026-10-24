<?php

namespace App\Imports\Support;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

final class ImportContext
{
    /** @var array<string, mixed> */
    private array $cache = [];

    public function __construct(
        public readonly User $user,
        public readonly string $mode,
        public readonly ?Project $project = null,
        public readonly ?int $boqId = null,
        public readonly ?int $importBatchId = null,
    ) {}

    public function remember(string $key, callable $resolver): mixed
    {
        if (! array_key_exists($key, $this->cache)) {
            $this->cache[$key] = $resolver();
        }

        return $this->cache[$key];
    }

    public function seen(string $bucket, string $key): bool
    {
        if (isset($this->cache['seen'][$bucket][$key])) {
            return true;
        }

        $this->cache['seen'][$bucket][$key] = true;

        return false;
    }

    public function once(string $key, callable $resolver): mixed
    {
        return Cache::store('array')->remember($key, 1, $resolver);
    }
}
