<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Задание CRM на сборку архива для zakaznoe.pochta.ru.
 *
 * Зеркало ZakaznoeBuildTask в CRM. Контракт строгий: лишнее поле на любом уровне -
 * 422, а не молча проигнорированные данные.
 */
class ZakaznoeBuildStoreRequest extends FormRequest
{
    private const FIELDS = [
        'mail_registry_id', 'build_uuid', 'callback_url', 'arbitrator_id', 'storage',
        'letters_per_part', 'registry', 'sender', 'settings', 'letters',
    ];

    public function authorize(): bool
    {
        // Доступ проверил ключ в middleware
        return true;
    }

    public function rules(): array
    {
        $providers = ['yandex_disk', 'onb_storage'];

        return [
            'mail_registry_id' => ['required', 'integer', 'min:1'],
            'build_uuid' => ['required', 'uuid'],
            'callback_url' => ['required', 'string', 'url', 'max:2000', $this->callbackGoesToCrm()],
            'arbitrator_id' => ['required', 'integer', 'min:1'],

            'storage' => ['required', 'array:provider,directory'],
            'storage.provider' => ['required', Rule::in($providers)],
            // Папка реестра в хранилище: абсолютный путь без переходов наверх
            'storage.directory' => ['required', 'string', 'max:1000', 'starts_with:/', 'not_regex:/(^|\/)\.\.(\/|$)/'],

            'letters_per_part' => ['required', 'integer', 'min:1', 'max:'.config('zakaznoe.max_letters_per_part')],

            'registry' => ['required', 'array:name,date_departure'],
            'registry.name' => ['required', 'string', 'max:255'],
            'registry.date_departure' => ['nullable', 'string', 'date_format:d.m.Y'],

            'sender' => ['required', 'array:name,address'],
            'sender.name' => ['required', 'string', 'max:255'],
            'sender.address' => ['required', 'string', 'max:500'],

            'settings' => ['required', 'array:letter_type,zuev'],
            'settings.letter_type' => ['required', 'integer', 'in:0,1'],
            'settings.zuev' => ['required', 'boolean'],

            'letters' => ['required', 'list', 'min:1', 'max:5000'],
            'letters.*' => ['array:letter_id,number,recipient,files'],
            'letters.*.letter_id' => ['required', 'integer', 'min:1', 'distinct'],
            'letters.*.number' => ['nullable', $this->scalar()],

            'letters.*.recipient' => ['required', 'array:recipient_type,org_name,inn,kpp,lastname,firstname,middlename,address'],
            'letters.*.recipient.recipient_type' => ['required', 'integer', 'in:0,1'],
            'letters.*.recipient.org_name' => ['nullable', 'string', 'max:255', 'required_if:letters.*.recipient.recipient_type,1'],
            // Реквизиты из карточки контрагента CRM не валидирует - строгость здесь сорвала бы весь реестр
            'letters.*.recipient.inn' => ['nullable', 'string', 'regex:/^\d{1,12}$/'],
            'letters.*.recipient.kpp' => ['nullable', 'string', 'regex:/^\d{1,9}$/'],
            'letters.*.recipient.lastname' => ['nullable', 'string', 'max:255', 'required_if:letters.*.recipient.recipient_type,0'],
            'letters.*.recipient.firstname' => ['nullable', 'string', 'max:255', 'required_if:letters.*.recipient.recipient_type,0'],
            'letters.*.recipient.middlename' => ['nullable', 'string', 'max:255'],
            'letters.*.recipient.address' => ['required', 'string', 'max:500'],

            'letters.*.files' => ['required', 'list', 'min:1', 'max:200'],
            'letters.*.files.*' => ['array:id,name,size,provider,remote_path'],
            'letters.*.files.*.id' => ['required', 'integer', 'min:1'],
            'letters.*.files.*.name' => ['required', 'string', 'max:255'],
            'letters.*.files.*.size' => ['required', 'integer', 'min:0'],
            'letters.*.files.*.provider' => ['required', Rule::in($providers)],
            'letters.*.files.*.remote_path' => ['required', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        $shape = 'Поле :attribute должно быть объектом только с полями контракта: ';

        return [
            'storage.array' => $shape.'provider, directory.',
            'registry.array' => $shape.'name, date_departure.',
            'sender.array' => $shape.'name, address.',
            'settings.array' => $shape.'letter_type, zuev.',
            'letters.*.array' => $shape.'letter_id, number, recipient, files.',
            'letters.*.recipient.array' => $shape.'recipient_type, org_name, inn, kpp, lastname, firstname, middlename, address.',
            'letters.*.files.*.array' => $shape.'id, name, size, provider, remote_path.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                foreach (array_diff(array_keys($this->all()), self::FIELDS) as $key) {
                    $validator->errors()->add($key, "Поле {$key} не входит в контракт задания.");
                }
            },
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        // Отказ - это рассинхрон CRM и воркера, его надо видеть в логах
        Log::warning('Zakaznoe build task rejected', [
            'mail_registry_id' => $this->input('mail_registry_id'),
            'build_uuid' => $this->input('build_uuid'),
            // Расхождение адресов CRM и AUAPP_URL иначе пришлось бы угадывать
            'callback_url' => $this->input('callback_url'),
            'crm_url' => config('zakaznoe.crm_url'),
            'errors' => $validator->errors()->toArray(),
        ]);

        parent::failedValidation($validator);
    }

    /**
     * callback_url ведёт в CRM из конфига. Не настроен адрес CRM - не принимаем
     * ничего: иначе ключ можно было бы отправить куда угодно.
     */
    private function callbackGoesToCrm(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $crm = rtrim((string) config('zakaznoe.crm_url'), '/');

            if ($crm === '' || !is_string($value) || !str_starts_with($value, $crm.'/')) {
                $fail('Адрес callback не ведёт в CRM, указанную в AUAPP_URL.');
            }
        };
    }

    /**
     * Номер исходящего в CRM - строка, но из базы может прийти и числом.
     */
    private function scalar(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (!is_string($value) && !is_int($value)) {
                $fail("Поле {$attribute} должно быть строкой или числом.");
            } elseif (mb_strlen((string) $value) > 50) {
                $fail("Поле {$attribute} длиннее 50 символов.");
            }
        };
    }
}
