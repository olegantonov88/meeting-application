<?php

namespace Tests\Unit;

use App\Services\Zakaznoe\ZakaznoeFileNames;
use PHPUnit\Framework\TestCase;

class ZakaznoeFileNamesTest extends TestCase
{
    public function test_names_file_by_letter_number(): void
    {
        $names = ZakaznoeFileNames::forLetters([
            ['letter_id' => 690, 'number' => '120'],
            ['letter_id' => 691, 'number' => 121],
        ]);

        $this->assertSame([690 => 'letter-120.pdf', 691 => 'letter-121.pdf'], $names);
    }

    public function test_falls_back_to_letter_id_without_number(): void
    {
        $names = ZakaznoeFileNames::forLetters([
            ['letter_id' => 690, 'number' => null],
            ['letter_id' => 691, 'number' => '№'],
        ]);

        $this->assertSame([690 => 'letter-id690.pdf', 691 => 'letter-id691.pdf'], $names);
    }

    public function test_keeps_only_latin_and_digits(): void
    {
        $names = ZakaznoeFileNames::forLetters([
            ['letter_id' => 1, 'number' => '120/1'],
            ['letter_id' => 2, 'number' => '№ 5-А'],
        ]);

        $this->assertSame([1 => 'letter-120-1.pdf', 2 => 'letter-5.pdf'], $names);
    }

    public function test_duplicate_numbers_get_letter_id_suffix(): void
    {
        $names = ZakaznoeFileNames::forLetters([
            ['letter_id' => 10, 'number' => '120'],
            ['letter_id' => 11, 'number' => '120'],
            ['letter_id' => 12, 'number' => '120-11'],
        ]);

        $this->assertSame('letter-120.pdf', $names[10]);
        $this->assertSame('letter-120-11.pdf', $names[11]);
        // Номер «120-11» совпал бы с уже выданным именем - получает свой суффикс
        $this->assertSame('letter-120-11-12.pdf', $names[12]);
        $this->assertCount(3, array_unique($names));
    }
}
