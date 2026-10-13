<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class SafeFavicon implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('The favicon must be a file.');

            return;
        }

        $extension = strtolower($value->getClientOriginalExtension());

        if (! in_array($extension, ['ico', 'png', 'svg'], true)) {
            $fail('The favicon must be an ICO, PNG, or SVG file.');

            return;
        }

        if ($extension === 'png' && @getimagesize($value->getRealPath()) === false) {
            $fail('The favicon must be a valid PNG file.');

            return;
        }

        if ($extension !== 'svg') {
            return;
        }

        $contents = (string) file_get_contents($value->getRealPath());

        if ($contents === '' || ! str_contains(strtolower($contents), '<svg')) {
            $fail('The favicon must be a valid SVG file.');

            return;
        }

        if (preg_match('/<script|javascript:|on[a-z]+\s*=/i', $contents) === 1) {
            $fail('The favicon SVG is not allowed.');
        }
    }
}
