<?php

namespace App\Chatbot\Support;

use App\Models\User;
use Illuminate\Support\Facades\Session;

final class ConversationContext
{
    private const SESSION_KEY = 'erp_assistant.context';

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(private array $data = []) {}

    public static function load(User $user): self
    {
        $stored = Session::get(self::SESSION_KEY, []);
        if (($stored['user_id'] ?? null) !== $user->id) {
            $stored = ['user_id' => $user->id];
        }

        return new self($stored);
    }

    public function save(): void
    {
        Session::put(self::SESSION_KEY, $this->data);
    }

    public function clear(): void
    {
        $userId = $this->data['user_id'] ?? null;
        $this->data = $userId ? ['user_id' => $userId] : [];
        $this->save();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function merge(array $patch): void
    {
        foreach ($patch as $key => $value) {
            if ($value === null) {
                unset($this->data[$key]);
            } else {
                $this->data[$key] = $value;
            }
        }
    }

    public function lastIntent(): ?string
    {
        return is_string($this->get('last_intent')) ? $this->get('last_intent') : null;
    }

    public function projectId(): ?int
    {
        $id = $this->get('project_id');

        return is_numeric($id) ? (int) $id : null;
    }

    public function vendorId(): ?int
    {
        $id = $this->get('vendor_id');

        return is_numeric($id) ? (int) $id : null;
    }
}
