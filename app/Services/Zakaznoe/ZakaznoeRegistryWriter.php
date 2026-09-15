<?php

namespace App\Services\Zakaznoe;

use App\Services\Zakaznoe\Exceptions\ZakaznoeBuildException;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * Опись части архива по шаблону zakaznoe.pochta.ru.
 *
 * Шаблон - файл Почты как есть: лист «Инструкция» и проверки ввода на 50 строк
 * не трогаем, заполняем строки со второй. Колонки:
 * A имя файла в архиве, B тип письма, C тип получателя, D организация, E ИНН,
 * F КПП, G-I ФИО, J адрес получателя, K адрес отправителя, L ЮЗЭУВ.
 */
class ZakaznoeRegistryWriter
{
    private const FIRST_ROW = 2;

    /**
     * @param  array<int, array{letter: array, path: string, file_name: string}>  $letters  письма части
     */
    public function write(array $task, array $letters, string $path): string
    {
        $template = (string) config('zakaznoe.template_path');

        if (!is_file($template)) {
            throw new ZakaznoeBuildException('template_missing', 'Не найден шаблон описи для zakaznoe.pochta.ru.');
        }

        try {
            $book = IOFactory::load($template);
        } catch (Throwable $e) {
            throw new ZakaznoeBuildException('template_broken', 'Не удалось открыть шаблон описи для zakaznoe.pochta.ru.', $e);
        }

        $sheet = $book->getSheetByName((string) config('zakaznoe.template_sheet'));

        if (!$sheet) {
            throw new ZakaznoeBuildException('template_broken', 'В шаблоне описи нет листа «'.config('zakaznoe.template_sheet').'».');
        }

        $letterType = (int) $task['settings']['letter_type'];
        // Уведомление о вручении бывает только у заказного
        $zuev = $letterType === 1 && (bool) $task['settings']['zuev'];
        $row = self::FIRST_ROW;

        foreach ($letters as $entry) {
            $recipient = $entry['letter']['recipient'];
            $company = (int) $recipient['recipient_type'] === 1;

            $values = [
                'A' => $entry['file_name'],
                'B' => $letterType,
                'C' => $company ? 1 : 0,
                'D' => $company ? $recipient['org_name'] : null,
                'E' => $company ? $recipient['inn'] : null,
                'F' => $company ? $recipient['kpp'] : null,
                'G' => $company ? null : $recipient['lastname'],
                'H' => $company ? null : $recipient['firstname'],
                'I' => $company ? null : $recipient['middlename'],
                'J' => $recipient['address'],
                'K' => $task['sender']['address'],
                'L' => $zuev ? 1 : null,
            ];

            foreach ($values as $column => $value) {
                if ($value === null || $value === '') {
                    continue;
                }

                // Коды - числами, как в выпадающих списках шаблона. Остальное строкой:
                // ИНН с ведущим нулём и индекс в адресе не должны стать числами
                is_int($value)
                    ? $sheet->setCellValue("{$column}{$row}", $value)
                    : $sheet->setCellValueExplicit("{$column}{$row}", (string) $value, DataType::TYPE_STRING);
            }

            $row++;
        }

        File::ensureDirectoryExists(dirname($path));

        try {
            (new Xlsx($book))->save($path);
        } finally {
            $book->disconnectWorksheets();
        }

        return $path;
    }
}
