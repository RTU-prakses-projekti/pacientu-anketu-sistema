<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class DateTimeDisplay
{
    public static function format(?DateTimeInterface $value, string $format = 'd.m.Y H:i'): ?string
    {
        if ($value === null) {
            return null;
        }

        return DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new DateTimeZone(config('app.display_timezone', 'Europe/Riga')))
            ->format($format);
    }

    public static function parseLocalInput(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value, config('app.display_timezone', 'Europe/Riga'))->utc();
    }

    public static function formatAuditMetadata(array $metadata): array
    {
        foreach ($metadata as $key => $value) {
            if (is_array($value)) {
                $metadata[$key] = self::formatAuditMetadata($value);
                continue;
            }

            if (is_string($value) && str_ends_with((string) $key, '_at')
                && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $value)) {
                try {
                    $metadata[$key] = self::format(CarbonImmutable::parse($value));
                } catch (\Throwable) {
                    // Leave malformed or non-timestamp audit values unchanged.
                }
            }
        }

        return $metadata;
    }
}
