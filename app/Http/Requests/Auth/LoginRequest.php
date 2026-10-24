<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Services\Security\LoginLockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $lockout = app(LoginLockout::class);
        $email = $lockout->normalize((string) $this->input('email'));
        $ip = (string) $this->ip();

        $lockout->assertCanAttempt($this);

        $lockout->serializeAccount($email, function () use ($lockout, $email, $ip): void {
            $lockout->assertCanAttempt($this);

            if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
                $lockout->recordFailure($email, $ip);

                throw ValidationException::withMessages([
                    'email' => trans('auth.failed'),
                ]);
            }

            if (Auth::user()?->status !== User::STATUS_ACTIVE) {
                Auth::logout();

                throw ValidationException::withMessages([
                    'email' => 'This account is inactive.',
                ]);
            }

            $lockout->recordSuccess($email, $ip);
        });
    }
}
