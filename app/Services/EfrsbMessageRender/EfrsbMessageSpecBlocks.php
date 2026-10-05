<?php

namespace App\Services\EfrsbMessageRender;

use App\Services\EfrsbMessageRender\EfrsbMessageFormatter as F;

/**
 * Второй уровень: типы сообщений, для которых нет html-образца Федресурса.
 *
 * Всё здесь - ИЗ СПЕЦИФИКАЦИИ, НЕ СВЕРЕНО: поля и подписи взяты из "Описание структуры публикаций в ЕФРСБ" (Интерфакс),
 * подписи приведены к стилю Федресурса. Разметка - теми же блоками, что у сверенных типов.
 * Когда появится html-образец типа, сверяем, переносим в EfrsbMessageRenderer и убираем отсюда.
 *
 * Здесь же общие помощники для разбора JSON: коллекции Федресурс присылает то массивом, то обёрткой, то одним объектом
 */
trait EfrsbMessageSpecBlocks
{
    /**
     * Полномочия исполнительных органов при временной администрации. Из спецификации, не сверено
     */
    private const AUTHORITY_LIMITATIONS = [
        'Limited' => 'Ограничены',
        'Suspended' => 'Приостановлены',
    ];

    /**
     * Причина прекращения деятельности временной администрации. Из спецификации, не сверено
     */
    private const ADMINISTRATION_TERMINATION_CAUSES = [
        'TermExpiration' => 'Истечение срока полномочий',
        'EarlyTermination' => 'Досрочное прекращение деятельности',
        'OtherCause' => 'Иное',
    ];

    /**
     * Элементы коллекции: [..], {обёртка: [..]} или один объект {..} -> список.
     * $keys - возможные имена обёртки ("creditOrganizationInfo" и т.п.)
     */
    private function items(mixed $value, string ...$keys): array
    {
        if (!is_array($value) || $value === []) return [];

        if (!array_is_list($value)) {
            foreach ($keys as $key) {
                if (array_key_exists($key, $value)) return $this->items($value[$key]);
            }
            return [$value];
        }

        // Элементы бывают и простыми значениями (номера отмененных актов)
        return array_values(array_filter($value, fn ($item) => $item !== null && $item !== ''));
    }

    /**
     * true / "true" / 1 -> true, false / "false" / 0 -> false, остальное -> null
     */
    private function bool(mixed $value): ?bool
    {
        if (is_bool($value)) return $value;
        if ($value === null || $value === '') return null;

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private function yesNo(mixed $value): ?string
    {
        $bool = $this->bool($value);

        return $bool === null ? null : ($bool ? 'Да' : 'Нет');
    }

    /**
     * Лицо, у которого ФИО лежит не в fio, а отдельными полями (участник торгов, кредитор в освобождении от обязательств, АУ в судебном акте).
     * Иностранцам - код и страна. Поля бывают и в верхнем регистре (INN, OGRN), как в спецификации
     */
    private function flatParticipant(?array $person): ?string
    {
        if (!$person) return null;

        $get = fn (string $key) => $person[$key] ?? $person[strtoupper($key)] ?? null;
        $name = F::fio($person['fio'] ?? null)
            ?? F::join([$get('lastName'), $get('firstName'), $get('middleName')], ' ')
            ?? $get('name');

        return F::withDetails($name, [
            'ИНН' => $get('inn'),
            'СНИЛС' => F::snils($get('snils')),
            'ОГРНИП' => $get('ogrnip'),
            'ОГРН' => $get('ogrn'),
            'Рег. номер' => $get('regnum'),
            'Аналог ИНН' => $get('analogueInn'),
            $person['codeType'] ?? 'Код' => $person['code'] ?? $person['identifier'] ?? null,
            'Страна' => is_array($person['country'] ?? null) ? ($person['country']['name'] ?? null) : ($person['country'] ?? null),
        ]);
    }

    /**
     * Типы, описанные только в спецификации. Из спецификации, не сверено
     */
    private function specContentBlocks(?string $type, array $content, array $message): array
    {
        return match ($type) {
            'AppointAdministration' => [
                $this->rows('bodyInfo', [
                    ...$this->administrationActRows($content),
                    ['Дата назначения временной администрации', F::date($content['administrationDateFrom'] ?? null)],
                    ['Основания назначения временной администрации', $content['reasons'] ?? null, true],
                    ['Состав временной администрации', $content['members'] ?? null, true],
                    ['Срок действия временной администрации', $content['administrationPeriod'] ?? null],
                    ['Полномочия исполнительных органов', self::AUTHORITY_LIMITATIONS[$content['authorityCredentionalsLimitation'] ?? ''] ?? null],
                ]),
                ...$this->administrationDirectorBlocks($content['director'] ?? null),
            ],
            'ChangeAdministration' => [
                $this->rows('bodyInfo', [
                    ...$this->administrationActRows($content),
                    ['Основания изменения состава временной администрации', $content['reasons'] ?? null, true],
                ]),
                ...$this->administrationDirectorBlocks($content['director'] ?? null),
            ],
            'TerminationAdministration' => [
                $this->rows('bodyInfo', [
                    ...$this->administrationActRows($content),
                    ['Причина прекращения деятельности', self::ADMINISTRATION_TERMINATION_CAUSES[$content['cause'] ?? ''] ?? null],
                    ['Основания прекращения деятельности временной администрации', $content['otherCauseDescription'] ?? null, true],
                ]),
                ...$this->administrationDirectorBlocks($content['director'] ?? null),
            ],
            'ReducingSizeShareCapital' => [$this->rows('bodyInfo', [
                ['Уставный капитал уменьшен до суммы, руб.', F::money($content['charterCapitalSum'] ?? null)],
                ['Дата принятия нормативного акта Банка России', F::date($content['normativeActAdoptionDate'] ?? null)],
                ['Отменено решение банка об увеличении размера уставного капитала', $this->yesNo($content['increaseCapitalDecisionCanceled'] ?? null)],
            ])],
            'ImpendingTransferAssets', 'TransferAssets' => $this->listBlocks('Кредитные организации - приобретатели',
                $this->items($content['creditOrganizations'] ?? null, 'creditOrganizationInfo'),
                fn ($organization) => [
                    ['Наименование', $organization['name'] ?? null],
                    ['Адрес', $organization['address'] ?? null],
                    ['ОГРН', $organization['ogrn'] ?? null],
                    ['ИНН', $organization['inn'] ?? null],
                    ['Критерии отнесения обязательств к числу обязательств, передаваемых приобретателю', $organization['acquirerLiabilitiesClassificationCriteria'] ?? null, true],
                    ['Порядок получения кредиторами информации о передаваемых обязательствах', $organization['transferredLiabilitiesObtainingOrder'] ?? null, true],
                ]),
            'TransferInsurancePortfolio' => [
                $this->rows('bodyInfo', [
                    ['Предполагаемая дата передачи портфеля', F::date($content['expectedDeliveryDate'] ?? null)],
                    ['Основания передачи страхового портфеля', $content['portfolioTransferReason'] ?? null, true],
                    ['Сведения об ограничении / приостановлении полномочий исполнительного органа должника', $content['authorityLimitationInfo'] ?? null, true],
                ]),
                $this->title('Управляющая страховая организация', 'msg'),
                $this->rows('bodyInfo', [
                    ['Наименование', $content['insuranceOrganization']['name'] ?? null],
                    ['Адрес', $content['insuranceOrganization']['address'] ?? null],
                    ['ОГРН', $content['insuranceOrganization']['ogrn'] ?? null],
                    ['ИНН', $content['insuranceOrganization']['inn'] ?? null],
                ]),
            ],
            'StartSettlement' => [$this->rows('bodyInfo', [['Дата начала расчетов', F::date($content['settlementStartDate'] ?? null)]])],
            'StartOfExtrajudicialBankruptcy' => $this->extrajudicialStartBlocks($content),
            'TerminationOfExtrajudicialBankruptcy' => [$this->rows('bodyInfo', [
                ['Сообщение о возбуждении процедуры', $this->messageReference($message, $content['startOfExtrajudicialBankruptcyMessageNumber'] ?? null)],
                ['Причина прекращения', array_values(array_filter([
                    $this->bool($content['propertyStatusChanged'] ?? null) ? 'Изменение имущественного положения гражданина, позволяющего полностью или в значительной части исполнить свои обязательства перед кредиторами' : null,
                    $this->bool($content['courtDecisionIssued'] ?? null) ? 'Вынесение определения арбитражного суда о признании обоснованным заявления о признании гражданина банкротом и введении реструктуризации долгов гражданина' : null,
                    $content['otherReason'] ?? null,
                ])), 'list' => true],
            ])],
            'CompletionOfExtrajudicialBankruptcy' => [$this->rows('bodyInfo', [
                ['Сообщение о возбуждении процедуры', $this->messageReference($message, $content['startOfExtrajudicialBankruptcyMessageNumber'] ?? null)],
            ])],
            'ReturnOfApplicationOnExtrajudicialBankruptcy' => [$this->rows('bodyInfo', [
                ['Дата возврата заявления', F::date($content['date'] ?? null)],
                ["Отсутствие на момент проверки сведений о возвращении\nисполнительного документа взыскателю по основанию,\nпредусмотренному п. 4 ч. 1 ст. 46 Федерального закона\n\"Об исполнительном производстве\"", $this->yesNo($content['noReturnOfEnforcementDocument'] ?? null)],
                ["Наличие сведений о ведении иных исполнительных производств,\nвозбужденных после даты возвращения исполнительного\nдокумента взыскателю и не оконченных или не прекращенных\nна момент проверки сведений", $this->yesNo($content['activeEnforcementProceeding'] ?? null)],
            ])],
            'ReturnOfApplicationOnExtrajudicialBankruptcy2' => [
                $this->rows('bodyInfo', [
                    ['МФЦ, принявший заявление гражданина', $this->flatParticipant($content['mfc'] ?? null)],
                    ['Категория гражданина', $content['personCategory']['description'] ?? null],
                    ['Дата возврата заявления', F::date($content['date'] ?? null)],
                    ['Причины возврата', array_map(fn ($reason) => $reason['description'] ?? null, $this->items($content['returnReasons'] ?? null, 'returnReason')), 'list' => true],
                ]),
                ...$this->smevBlocks($content['smevRequestResults'] ?? null),
            ],
            default => [],
        };
    }

    /**
     * Акт о временной администрации: название, дата "от", номер
     */
    private function administrationActRows(array $content): array
    {
        return [
            ['Название акта', $content['decisionName'] ?? null],
            ['Дата акта', F::date($content['decisionDate'] ?? null)],
            ['Номер акта', $content['decisionNumber'] ?? null],
        ];
    }

    private function administrationDirectorBlocks(?array $director): array
    {
        if (!$director) return [];
        $sro = $director['sro'] ?? [];
        $get = fn (array $item, string $key) => $item[$key] ?? $item[strtoupper($key)] ?? null;

        return [
            $this->title('Руководитель временной администрации', 'msg'),
            $this->rows('bodyInfo', [
                ['ФИО', $director['name'] ?? null],
                ['Адрес для корреспонденции', $director['address'] ?? null],
                ['ИНН', $get($director, 'inn')],
                ['СНИЛС', F::snils($get($director, 'snils'))],
                ['СРО АУ', F::withDetails($sro['sroName'] ?? null, ['ИНН' => $get($sro, 'inn'), 'ОГРН' => $get($sro, 'ogrn')])],
                ['Адрес СРО АУ', $sro['legalAddress'] ?? null],
            ]),
        ];
    }

    /**
     * Список однотипных элементов: подзаголовок, по таблице на элемент, после каждой - разделитель (как требования кредиторов)
     */
    private function listBlocks(string $title, array $items, \Closure $rows): array
    {
        $blocks = [];
        foreach ($items as $item) {
            $blocks[] = $this->rows('bodyInfo', $rows($item), false, 'width: 800px;');
            $blocks[] = $this->separator();
        }

        return $blocks ? [$this->title($title, 'msg'), ...$blocks] : [];
    }

    /**
     * Возбуждение внесудебного банкротства: МФЦ, категория, кредиторы, банки, результаты запросов СМЭВ
     */
    private function extrajudicialStartBlocks(array $content): array
    {
        return [
            $this->rows('bodyInfo', [
                ['МФЦ, принявший заявление гражданина', $this->flatParticipant($content['mfc'] ?? null)],
                ['Категория гражданина', $content['personCategory']['description'] ?? null],
                ["Гражданин зарегистрирован или был зарегистрирован\nв качестве индивидуального предпринимателя", $this->yesNo($content['isIndividualEntrepreneur'] ?? null)],
            ]),
            ...$this->extrajudicialCreditorsBlocks('Кредиторы по обязательствам, не связанным с предпринимательской деятельностью', $content['creditorsNonFromEntrepreneurship'] ?? null),
            ...$this->extrajudicialCreditorsBlocks('Кредиторы по обязательствам, связанным с предпринимательской деятельностью', $content['creditorsFromEntrepreneurship'] ?? null),
            $this->title('Кредитные организации', 'msg'),
            $this->grid('personInfo', ['Наименование', 'БИК'],
                array_map(fn ($bank) => [$bank['name'] ?? null, $bank['bankIdentifier'] ?? null], $this->items($content['banks'] ?? null, 'bank')),
                [null, 'align:center']),
            ...$this->smevBlocks($content['smevRequestResults'] ?? null),
        ];
    }

    private function extrajudicialCreditorsBlocks(string $title, ?array $creditors): array
    {
        if (!$creditors) return [];

        return [
            $this->title($title, 'msg'),
            $this->title('Денежные обязательства', 'msg', false),
            $this->grid('personInfo', ['Наименование кредитора', 'Регион', 'Местонахождение кредитора', 'Содержание обязательства', 'Основание возникновения', "Всего,\nруб.", "В том числе\nзадолженность, руб.", "Штрафы, пени и иные\nсанкции, руб."],
                array_map(fn ($obligation) => [
                    $obligation['creditorName'] ?? null,
                    $obligation['creditorRegion'] ?? null,
                    $obligation['creditorLocation'] ?? null,
                    $obligation['content'] ?? null,
                    $obligation['basis'] ?? null,
                    F::money($obligation['totalSum'] ?? null),
                    F::money($obligation['debtSum'] ?? null),
                    F::money($obligation['penaltySum'] ?? null),
                ], $this->items($creditors['monetaryObligations'] ?? null, 'monetaryObligation')),
                [null, null, null, null, null, 'align:right', 'align:right', 'align:right']),
            $this->title('Обязательные платежи', 'msg', false),
            $this->grid('personInfo', ['Наименование налога, сбора или иного обязательного платежа', 'Недоимка, руб.', "Штрафы, пени и иные\nсанкции, руб."],
                array_map(fn ($payment) => [
                    $payment['name'] ?? null,
                    F::money($payment['sum'] ?? null),
                    F::money($payment['penaltySum'] ?? null),
                ], $this->items($creditors['obligatoryPayments'] ?? null, 'obligatoryPayment')),
                [null, 'align:right', 'align:right']),
            $this->msg('Неденежные обязательства:', $creditors['nonMonetaryObligations'] ?? null),
        ];
    }

    /**
     * Результаты запросов через СМЭВ: условие, организация, результат (описания приходят в данных)
     */
    private function smevBlocks(?array $results): array
    {
        return [
            $this->title('Результаты запросов, направленных через СМЭВ', 'msg'),
            $this->grid('personInfo', ['Проверяемое условие', 'Организация', 'Результат'],
                array_map(fn ($request) => [
                    $request['type']['description'] ?? null,
                    $request['authority'] ?? null,
                    $request['result']['description'] ?? null,
                ], $this->items($results['requests'] ?? $results ?? null, 'request')),
                [null, null, null]),
        ];
    }
}
