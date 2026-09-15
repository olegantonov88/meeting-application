<?php

namespace App\Services\Zakaznoe;

/**
 * Имена PDF внутри архива.
 *
 * Латиницей: кириллица в zip - частая причина кривых имён у принимающей стороны.
 * letter-{номер исходящего}.pdf, без номера - letter-id{id письма}.pdf.
 * Опись ссылается на это же имя, поэтому имена в пределах сборки уникальны.
 */
final class ZakaznoeFileNames
{
    /**
     * @param  array<int, array{letter_id: int, number?: string|int|null}>  $letters
     * @return array<int, string> letter_id → имя файла
     */
    public static function forLetters(array $letters): array
    {
        $names = [];
        $used = [];

        foreach ($letters as $letter) {
            $letterId = (int) $letter['letter_id'];
            $number = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($letter['number'] ?? '')), '-');

            $base = $number !== '' ? "letter-{$number}" : "letter-id{$letterId}";
            $name = "{$base}.pdf";

            // Номера бывают одинаковыми - тогда различаем по id письма
            for ($suffix = $letterId; isset($used[strtolower($name)]); $suffix++) {
                $name = "{$base}-{$suffix}.pdf";
            }

            $used[strtolower($name)] = true;
            $names[$letterId] = $name;
        }

        return $names;
    }
}
