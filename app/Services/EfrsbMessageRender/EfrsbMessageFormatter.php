<?php

namespace App\Services\EfrsbMessageRender;

use Illuminate\Support\Carbon;

/**
 * Форматирование значений сообщения ЕФРСБ так, как их показывает Федресурс.
 *
 * Сервис самодостаточный: без моделей и хелперов приложения, чтобы переносить в meeting-application копированием
 */
class EfrsbMessageFormatter
{
    /**
     * "2025-10-23T00:00:00" -> "23.10.2025", со временем -> "23.10.2025 11:00".
     * Уже готовую "01.02.1980" возвращаем как есть
     */
    public static function date(?string $value, bool $withTime = false): ?string
    {
        if ($value === null || trim($value) === '') return null;
        if (preg_match('/^\d{2}\.\d{2}\.\d{4}/', $value)) return $value;

        try {
            $date = Carbon::parse($value);
        } catch (\Throwable $e) {
            return $value;
        }

        return $withTime ? $date->format('d.m.Y H:i') : $date->format('d.m.Y');
    }

    /**
     * Дата и время из {dateTime, mskTimeZoneOffset} -> "07.03.2024 11:00 (Московское время МСК)".
     * Смещение не ноль -> "МСК+1"
     */
    public static function dateTimeWithZone(?array $value): ?string
    {
        if (!$value) return null;

        $dateTime = self::date($value['dateTime'] ?? $value['date'] ?? null, isset($value['dateTime']));
        if ($dateTime === null) return null;

        $zone = $value['mskTimeZoneOffset'] ?? null;
        if (!$zone) return $dateTime;

        // У Федресурса смещение через пробел: "МСК +2" (сверено с 24828254)
        $offset = (string) ($zone['code'] ?? '0');
        $msk = 'МСК' . ($offset === '0' || $offset === '' ? '' : ' ' . (str_starts_with($offset, '-') || str_starts_with($offset, '+') ? $offset : '+' . $offset));

        return $dateTime . ' (' . self::join([$zone['description'] ?? null, $msk], ' ') . ')';
    }

    /**
     * Срок {year, month, day} -> "0г 6м 0д", как у Федресурса
     */
    public static function duration(?array $value): ?string
    {
        if (!$value) return null;

        return (int) ($value['year'] ?? 0) . 'г ' . (int) ($value['month'] ?? 0) . 'м ' . (int) ($value['day'] ?? 0) . 'д';
    }

    /**
     * 39150 -> "39 150,00"
     */
    public static function money(int|float|string|null $value): ?string
    {
        if ($value === null || $value === '' || !is_numeric($value)) return null;

        return number_format((float) $value, 2, ',', ' ');
    }

    /**
     * Участник (кредитор и т.п.): ИП/гражданин - ФИО, организация - наименование, реквизиты в скобках.
     * "ИНДЖИРОВ БАСАНГ ЭДЯШЕВИЧ (ИНН 081401881567, ОГРНИП 304081417700041)"
     */
    public static function participant(?array $participant): ?string
    {
        if (!$participant) return null;

        return self::withDetails(self::fio($participant['fio'] ?? null) ?? $participant['name'] ?? null, [
            'ИНН' => $participant['inn'] ?? null,
            'СНИЛС' => self::snils($participant['snils'] ?? null),
            'ОГРНИП' => $participant['ogrnip'] ?? null,
            'ОГРН' => $participant['ogrn'] ?? null,
        ]);
    }

    /**
     * "12804668462" -> "128-046-684 62"
     */
    public static function snils(?string $value): ?string
    {
        if ($value === null || trim($value) === '') return null;

        $digits = preg_replace('/\D/', '', $value);
        if (strlen($digits) !== 11) return $value;

        return substr($digits, 0, 3) . '-' . substr($digits, 3, 3) . '-' . substr($digits, 6, 3) . ' ' . substr($digits, 9, 2);
    }

    /**
     * {lastName, firstName, middleName} -> "Фамилия Имя Отчество"
     */
    public static function fio(?array $fio): ?string
    {
        if (!$fio) return null;

        return self::join([$fio['lastName'] ?? null, $fio['firstName'] ?? null, $fio['middleName'] ?? null], ' ');
    }

    /**
     * Склеить непустые части
     */
    public static function join(array $parts, string $separator = ', '): ?string
    {
        $parts = array_filter(array_map(fn ($part) => is_string($part) ? trim($part) : $part, $parts), fn ($part) => $part !== null && $part !== '');

        return $parts ? implode($separator, $parts) : null;
    }

    /**
     * "Имя (ИНН 123, СНИЛС 456)" - реквизиты в скобках, только заполненные
     */
    public static function withDetails(?string $name, array $details): ?string
    {
        $details = array_filter($details, fn ($value) => $value !== null && trim((string) $value) !== '');
        $detailsText = implode(', ', array_map(fn ($label, $value) => $label . ' ' . $value, array_keys($details), $details));

        if ($name === null || trim($name) === '') return $detailsText ?: null;

        return $detailsText ? trim($name) . ' (' . $detailsText . ')' : trim($name);
    }
}
