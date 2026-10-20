<?php

namespace App\Chatbot\Support;

use Carbon\CarbonInterface;

final class ListItem
{
    /**
     * Build a readable chat list row for the assistant UI.
     *
     * @return array{title: string, subtitle: ?string, amount: ?string, date: ?string, meta: ?string, url: ?string}
     */
    public static function make(
        string $title,
        ?string $subtitle = null,
        ?string $amount = null,
        mixed $date = null,
        ?string $meta = null,
        ?string $url = null,
    ): array {
        return [
            'title' => $title,
            'subtitle' => $subtitle,
            'amount' => $amount,
            'date' => self::formatDate($date),
            'meta' => $meta,
            'url' => $url,
        ];
    }

    public static function formatDate(mixed $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        if ($date instanceof CarbonInterface) {
            return $date->format('d M Y');
        }

        try {
            return \Illuminate\Support\Carbon::parse((string) $date)->format('d M Y');
        } catch (\Throwable) {
            return (string) $date;
        }
    }
}
