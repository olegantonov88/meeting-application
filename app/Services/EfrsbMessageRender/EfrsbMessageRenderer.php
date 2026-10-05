<?php

namespace App\Services\EfrsbMessageRender;

use App\Services\EfrsbMessageRender\EfrsbMessageFormatter as F;

/**
 * HTML сообщения ЕФРСБ из JSON Федресурса в той же разметке и с тем же CSS, что html-сообщения Федресурса.
 *
 * Хранится только исходный JSON, HTML строится при каждом выводе: поправили вывод - сразу видно во всех сообщениях.
 * Сервис самодостаточный (этот каталог + resources/views/efrsb-message-render + enum EfrsbDebtorMessageBodyFormat).
 * Копия лежит в auapp и в meeting-application: правки переносить в оба проекта копированием, без изменений.
 *
 * У всех типов выводим: шапку (№ и дата публикации), должника, кем опубликовано и текст.
 * Строки шапки и публикуемые сведения перед текстом - только у типов, сверенных с html Федресурса
 * (методы на тип в typeHeadRows() и contentBlocks()). У остальных типов этого нет, пока не найдём образец.
 *
 * Публикуемые сведения собираются из блоков, как у Федресурса:
 * - rows: таблица "подпись - значение" с чередованием строк (class bodyInfo/headInfo);
 * - grid: таблица со столбцами и заголовком (например, суд | № | дата акта, class courtInfo);
 * - field: одна строка "подпись - значение" отдельной таблицей без рамок (table-without-border);
 * - info: строки-абзацы div.AdditionalInfo.
 *
 * Правила вывода:
 * - выводим только поля, для которых есть русская подпись. Неизвестные поля JSON не показываем вовсе, английских подписей нет;
 * - подпись есть, а данных нет (нет поля, null, пустая строка, одни пробелы) - строку не показываем, пустые блоки и секции тоже
 *
 * Два уровня подписей:
 * 1) сверено с html Федресурса - комментарий "Сверено с html сообщения N";
 * 2) образца нет, но поле или значение описано в спецификации "Описание структуры публикаций в ЕФРСБ" (Интерфакс) -
 *    подпись из спецификации в стиле Федресурса, комментарий "Из спецификации, не сверено". Типы целиком из спецификации - в EfrsbMessageSpecBlocks.
 * Сверенное главнее: при расхождении с образцом правим под образец. Нет ни образца, ни описания - не выводим
 */
class EfrsbMessageRenderer
{
    use EfrsbMessageSpecBlocks;

    public const VIEW = 'efrsb-message-render.message';

    /**
     * С какой даты публикации Федресурс подписывает почту "Эл. почта", до неё - "E-mail".
     * По образцам: 12.12.2024 ещё "E-mail", 06.04.2026 уже "Эл. почта". Точную дату не знаем, уточнить по сообщению 2025 года
     */
    private const EMAIL_LABEL_SINCE = '2025-01-01';

    /**
     * Тип арбитражного управляющего. Financial сверен (24768511), остальные - из спецификации, не сверено
     */
    private const ARBITR_MANAGER_TYPES = [
        'Financial' => 'Финансовый управляющий',
        // Из спецификации, не сверено
        'ActingFinancial' => 'Исполняющий обязанности финансового управляющего',
        'Administrative' => 'Административный управляющий',
        'ActingAdministrative' => 'Исполняющий обязанности административного управляющего',
        'External' => 'Внешний управляющий',
        'ActingExternal' => 'Исполняющий обязанности внешнего управляющего',
        'Temporary' => 'Временный управляющий',
        'ActingTemporary' => 'Исполняющий обязанности временного управляющего',
        'Concours' => 'Конкурсный управляющий',
        'ActingConcours' => 'Исполняющий обязанности конкурсного управляющего',
    ];

    /**
     * Признаки преднамеренного / фиктивного банкротства. NotFound сверен (24830653), остальные - из спецификации, не сверено
     */
    private const BANKRUPTCY_SIGNS = [
        'NotFound' => 'Не выявлены',
        // Из спецификации, не сверено
        'Found' => 'Выявлены',
        'NotSearched' => 'Проверка не проводилась',
    ];

    /**
     * Вид торгов и форма подачи предложения о цене. PublicOffer и Public сверены (24830116, 24827193)
     */
    private const TRADE_TYPES = [
        'PublicOffer' => 'Публичное предложение',
        // Из спецификации, не сверено
        'OpenedAuction' => 'Открытый аукцион',
        'ClosedAuction' => 'Закрытый аукцион',
        'OpenedConcours' => 'Открытый конкурс',
        'ClosedConcours' => 'Закрытый конкурс',
        'ClosePublicOffer' => 'Закрытое публичное предложение',
    ];

    private const PRICE_TYPES = [
        'Public' => 'Открытая',
        // Из спецификации, не сверено
        'Private' => 'Закрытая',
    ];

    /**
     * Единица шага и задатка лота. Percent сверен
     */
    private const STEP_UNITS = [
        'Percent' => '%',
        // Из спецификации, не сверено
        'Currency' => 'руб.',
    ];

    /**
     * Итог по лоту в результатах торгов (столбец "Победитель/Покупатель"). TradeFailed сверен (24830540)
     */
    private const LOT_STATUSES = [
        'TradeFailed' => 'торги признаны несостоявшимися',
        // Из спецификации, не сверено
        'Unknown' => 'неизвестен',
        'TradeSuccessed' => 'торги признаны состоявшимися',
        'TradeFailedSoldToTheOnlyParticipant' => 'торги признаны несостоявшимися, лот продан единственному участнику',
        'TradeBiddingEndBankruptcyCreditor' => 'торги завершены вследствие оставления конкурсным кредитором предмета залога за собой',
    ];

    public function render(array $message): string
    {
        return view(self::VIEW, $this->build($message))->render();
    }

    /**
     * Данные для шаблона. Отдельно от render, чтобы проверять без Blade
     *
     * @return array{sections: array, text: ?string}
     */
    public function build(array $message): array
    {
        $content = $message['content'] ?? [];
        $messageContent = $content['messageInfo']['messageContent'] ?? [];
        $type = $content['messageInfo']['messageType'] ?? $message['messageType'] ?? null;

        $sections = [
            ['title' => null, 'blocks' => [$this->rows('headInfo', [...$this->typeHeadRows($type, $messageContent), ...$this->headRows($message)])]],
            ['title' => 'Должник', 'blocks' => [$this->rows('headInfo', $this->bankruptRows($message))]],
            ['title' => 'Кем опубликовано', 'blocks' => [$this->rows('headInfo', [...$this->publisherRows($message), ...$this->typePublisherRows($type, $messageContent, $message)])]],
            ['title' => 'Публикуемые сведения', 'blocks' => $this->contentBlocks($type, $messageContent, $message)],
        ];

        foreach ($sections as &$section) {
            $section['blocks'] = $this->cleanBlocks($section['blocks']);
        }
        unset($section);

        return [
            'sections' => array_values(array_filter($sections, fn ($section) => $section['blocks'])),
            'text' => $messageContent['text'] ?? null,
            'afterText' => $this->cleanBlocks([...$this->afterTextBlocks($type, $messageContent, $message), $this->filesBlock($message)]),
        ];
    }

    private function cleanBlocks(array $blocks): array
    {
        $blocks = array_values(array_filter(array_map(fn ($block) => $this->cleanBlock($block), $blocks)));

        // Подзаголовок без блока с данными после него не выводим. Подзаголовки бывают вложенными
        // ("Объект ... и земельный участок" -> "Земельный участок" -> таблица), поэтому смотрим через цепочку заголовков
        $hasDataAfter = function (int $i) use ($blocks) {
            for ($j = $i + 1; isset($blocks[$j]); $j++) {
                if ($blocks[$j]['type'] !== 'title') return true;
            }
            return false;
        };

        return array_values(array_filter($blocks, fn ($block, $i) => $block['type'] !== 'title' || $hasDataAfter($i), ARRAY_FILTER_USE_BOTH));
    }

    /**
     * Подзаголовок внутри блока ("Сведения о судебном акте" - div.containerTitle.msg, "Привлекаемые лица" - div.msg)
     */
    private function title(string $title, string $class = 'containerTitle msg', bool $bold = true): array
    {
        return ['type' => 'title', 'class' => $class, 'bold' => $bold, 'rows' => [[$title, $title]]];
    }

    /**
     * Разделитель <hr> между элементами списка (привлекаемые лица и т.п.)
     */
    private function separator(): array
    {
        return ['type' => 'separator', 'rows' => [['', '-']]];
    }

    /**
     * Таблица "подпись - значение". Строка: [подпись, значение] или [подпись, значение, true] - значение с переносами строк.
     * В строке можно задать 'labelClass' (иначе класс таблицы) и 'valueClass' (например, money).
     * У Федресурса чередование строк начинается то с even, то с odd ($firstOdd), null - строки без класса.
     * $style - как у Федресурса у некоторых таблиц (ширина), $labelClass - primary / primary-long и т.п.
     */
    private function rows(string $class, array $rows, ?bool $firstOdd = false, ?string $style = null, string $labelClass = 'primary'): array
    {
        return ['type' => 'rows', 'class' => $class, 'rows' => $rows, 'firstOdd' => $firstOdd, 'style' => $style, 'labelClass' => $labelClass];
    }

    /**
     * Таблица со столбцами. $head - подписи столбцов, строкой или [подпись, style].
     * $align - по столбцам: "class:center" (class="center") или "align:right" (align="right"), как у Федресурса.
     * У всех строк один класс ($rowClass, у Федресурса в таких таблицах все строки odd). Переносы строк в ячейках - <br>
     */
    private function grid(string $class, array $head, array $rows, array $align = [], ?string $style = null, ?string $rowClass = 'odd'): array
    {
        return [
            'type' => 'grid',
            'class' => $class,
            'head' => array_map(fn ($item) => is_array($item) ? $item : [$item, null], $head),
            'rows' => $rows,
            'align' => $align,
            'style' => $style,
            'rowClass' => $rowClass,
        ];
    }

    private function field(string $label, ?string $value): array
    {
        return ['type' => 'field', 'rows' => [[$label, $value]]];
    }

    /**
     * Абзац div.msg с жирной подписью, как сам текст сообщения ("Причина отмены:"). Переносы строк сохраняются.
     * Пустая подпись - абзац без подписи ("Собрание проведено арбитражным управляющим")
     */
    private function msg(string $label, ?string $value): array
    {
        return ['type' => 'msg', 'rows' => [[$label, $value, true]]];
    }

    /**
     * Строки-абзацы div.AdditionalInfo: [подпись перед значением, значение]. Подпись может быть пустой
     */
    private function info(array $rows): array
    {
        return ['type' => 'info', 'rows' => $rows];
    }

    /**
     * Убрать пустые значения. Федресурс присылает значения с пробелами по краям ("А64-43/2023 "),
     * а переносы строк в обычных значениях таблиц выбрасывает (пункты повестки у него идут подряд) - делаем так же.
     * Переносы оставляем в ячейках grid и в строках, помеченных как многострочные. Блок без данных - null
     */
    private function cleanBlock(array $block): ?array
    {
        $trim = fn ($value) => is_string($value) ? trim($value) : $value;
        $oneLine = fn ($value) => is_string($value) ? trim(str_replace(["\r\n", "\n", "\r"], '', $value)) : $value;
        $filled = fn ($value) => $value !== null && $value !== '';

        if ($block['type'] === 'grid') {
            // Строку таблицы оставляем, если заполнена хоть одна ячейка
            $block['rows'] = array_values(array_filter(
                array_map(fn ($row) => array_map($trim, $row), $block['rows']),
                fn ($row) => array_filter($row, $filled)
            ));
        } else {
            // Остальные ключи строки (labelClass, valueClass, rowClass, ...) сохраняем. Строка-заголовок живёт без значения.
            // Значение-список (list) - без пустых элементов
            $value = fn ($row) => ($row['list'] ?? false)
                ? array_values(array_filter($row[1] ?? [], fn ($item) => is_array($item) ? $filled($item[0] ?? null) : $filled($item)))
                : (($row[2] ?? false) ? $trim($row[1] ?? null) : $oneLine($row[1] ?? null));
            $block['rows'] = array_values(array_filter(
                array_map(fn ($row) => array_replace($row, [1 => $value($row)]), $block['rows']),
                fn ($row) => ($row['heading'] ?? false) || ($row['hr'] ?? false) || ($row[1] !== [] && $filled($row[1]))
            ));
            // Блок из одних заголовков и разделителей - пустой
            if (!array_filter($block['rows'], fn ($row) => !($row['heading'] ?? false) && !($row['hr'] ?? false))) $block['rows'] = [];
        }

        return $block['rows'] ? $block : null;
    }

    /**
     * Прикрепленные к сообщению документы, в самом конце. Подпись и состав как у Федресурса: все файлы, включая .sig (24826345).
     * У Федресурса список стоит под сообщением, вне div.containerInfo, и файлы там - ссылки на скачивание.
     * У нас только имена: ссылки Федресурса работают лишь на его сайте
     */
    private function filesBlock(array $message): array
    {
        $files = $message['docs'] ?? $message['content']['fileInfoList'] ?? [];

        return ['type' => 'files', 'rows' => [[
            'Прикрепленные документы',
            array_map(fn ($file) => is_array($file) ? ($file['name'] ?? null) : null, is_array($files) ? $files : []),
            'list' => true,
        ]]];
    }

    /**
     * Блоки после текста сообщения: свои у некоторых типов (таблица лотов и т.п.)
     */
    private function afterTextBlocks(?string $type, array $content, array $message): array
    {
        return match ($type) {
            'TradeResult' => [$this->tradeResultLots($content['lotTable'] ?? [])],
            'Auction2', 'ChangeAuction2' => [
                $this->auctionLots($content['lotTable'] ?? []),
                $this->msg('Дополнительная информация:', $content['additionalText'] ?? null),
            ],
            default => [],
        };
    }

    /**
     * Сведения о заключении договоров купли-продажи: площадка, затем все договоры одной таблицей через <hr>.
     * Сверено с html сообщения 24817360 (договоры заключены). Сведения о незаключении не видели - не выводим
     */
    private function saleContractBlocks(array $content): array
    {
        $rows = [];
        foreach ($content['contracts'] ?? [] as $contract) {
            $purchaser = $contract['purchaserInfo'] ?? [];
            $hasPurchaser = !empty(trim((string) ($purchaser['name'] ?? ''))) || !empty($purchaser['inn']);
            $failure = $contract['failureWinnerInfo'] ?? [];
            $hasFailure = !empty(trim((string) ($failure['name'] ?? ''))) || !empty($failure['inn']);
            $title = ['labelClass' => 'primary title'];

            $rows = [
                ...$rows,
                ['Номер лота', $contract['lotNumber'] ?? null],
                ['Описание', $contract['description'] ?? null],
                ['Сведения о заключении договора', $contract['conclusionInfo'] ?? null],
                ['Номер договора', $contract['number'] ?? null],
                ['Дата заключения договора', F::date($contract['conclusionDate'] ?? null)],
                ['Цена приобретения имущества, руб.', F::money($contract['propertyPurchasePrice'] ?? null)],
                ...($hasPurchaser ? [['Информация о покупателе, с которым заключен договор', null, 'heading' => true, 'labelClass' => 'block']] : []),
                ['Наименование покупателя', $purchaser['name'] ?? null, ...$title],
                ['ИНН', $purchaser['inn'] ?? null, ...$title],
                // ОГРН покупателя и победитель, отказавшийся от договора - из спецификации, не сверено
                ['ОГРН / ОГРНИП', $purchaser['ogrn'] ?? null, ...$title],
                ...($hasFailure ? [['Победитель, отказавшийся от заключения договора', null, 'heading' => true, 'labelClass' => 'block']] : []),
                ['Наименование / ФИО', $failure['name'] ?? null, ...$title],
                ['ИНН', $failure['inn'] ?? null, ...$title],
                ['ОГРН / ОГРНИП', $failure['ogrn'] ?? null, ...$title],
                ['', null, 'hr' => true],
            ];
        }

        return [
            $this->rows('bodyInfo', [
                ['Торговая площадка', $content['tradePlaceName'] ?? null],
                // Из спецификации, не сверено
                ['Номер торгов на площадке', $content['tradeNumber'] ?? null],
            ]),
            $this->title('Заключенные договоры'),
            $this->rows('bodyInfo', $rows, null),
        ];
    }

    /**
     * Переход права собственности на объект незавершённого строительства: приобретатель, объекты, участки.
     * Сверено с html сообщения 24021228. Пустые поля Федресурс показывает прочерком - мы не выводим
     */
    private function transferOwnershipBlocks(array $content): array
    {
        $projects = [];
        foreach ($content['uncompletedBuildingProjects'] ?? [] as $project) {
            $projects[] = $this->title('Объект незавершенного строительства', 'level-1', false);
            $projects[] = $this->rows('bodyInfo level-1', [
                ['Строительный адрес', $project['address'] ?? null],
                ['Кадастровый номер', $project['cadastralNumber'] ?? null],
                ['Дополнительная информация', $project['additionalInfo'] ?? null],
            ], false, 'width: 500px;');
        }
        foreach ($content['landPlots'] ?? [] as $plot) {
            $projects[] = $this->title('Земельный участок', 'level-1', false);
            $projects[] = $this->rows('bodyInfo level-1', [
                ['Кадастровый номер', $plot['cadastralNumber'] ?? null],
                ['Описание прав на участок', $plot['ownershipRightDescription'] ?? null],
                ['Дополнительная информация', $plot['additionalInfo'] ?? null],
            ], false, 'width: 500px;');
        }

        return [
            $this->title('Приобретатель прав на объект строительства и земельный участок', 'msg', false),
            $this->rows('bodyInfo level-1', [
                ["Дата вынесения определения суда \nо передаче имущества \nи обязательств застройщика", F::date($content['courtDecisionDate'] ?? null), 'labelClass' => 'primary-long'],
                ["Дата государственной регистрации \nперехода прав", F::date($content['transferOwnershipStateRegistrationDate'] ?? null)],
                ['Наименование приобретателя', $content['acquirerName'] ?? null],
                ['Адрес приобретателя', $content['acquirerAddress'] ?? null],
                ['ОГРН', $content['acquirerOgrn'] ?? null],
                ['ИНН', $content['acquirerInn'] ?? null],
            ]),
            ...($projects ? [$this->title('Объект незавершенного строительства и земельный участок', 'msg', false), ...$projects] : []),
        ];
    }

    /**
     * Объявление о торгах и его изменение: параметры торгов перед текстом.
     * Сверено с html сообщений 24830116 (Auction2) и 24827193 (ChangeAuction2)
     */
    private function auctionBlocks(array $content, array $message, bool $isChange): array
    {
        $application = $content['application'] ?? [];

        return [
            $this->rows('bodyInfo', [
                ['Вид торгов', self::TRADE_TYPES[$content['tradeType'] ?? ''] ?? null],
                // Из спецификации, не сверено. Только "да": у первых торгов Федресурс вряд ли пишет "нет"
                ['Повторные торги', $this->bool($content['isRepeat'] ?? null) ? 'Да' : null],
                ['Дата и время начала подачи заявок', F::dateTimeWithZone($application['dateTimeBegin'] ?? null)],
                ['Дата и время окончания подачи заявок', F::dateTimeWithZone($application['dateTimeEnd'] ?? null)],
                ['Правила подачи заявок', $application['rules'] ?? null, true],
                ['Дата и время торгов', F::dateTimeWithZone($content['auctionDateTime'] ?? null)],
                ['Форма подачи предложения о цене', self::PRICE_TYPES[$content['priceType'] ?? ''] ?? null],
                ['Место проведения', $content['tradeSite'] ?? null],
                ['Измененное сообщение', $isChange ? $this->messageReference($message, $content['changedMessageNumber'] ?? null) : null],
            ]),
            $this->msg('Причина изменения:', $isChange ? ($content['changeReason'] ?? null) : null),
        ];
    }

    /**
     * Лоты объявления о торгах, после текста. Шаг и задаток - в процентах (другие единицы не видели - не выводим)
     */
    private function auctionLots(array $lots): array
    {
        $withUnit = fn ($value, $unit) => ($money = F::money($value)) !== null && isset(self::STEP_UNITS[$unit ?? '']) ? $money . ' ' . self::STEP_UNITS[$unit] : null;

        return $this->grid('lotInfo', [['Номер лота', 'width:30px'], ['Описание', 'width:100px'], ['Начальная цена, руб', 'width:100px'], ['Шаг', 'width:100px'], ['Задаток', 'width:70px'], ['Информация о снижении цены', 'width:100px'], 'Классификация имущества'],
            array_map(fn ($lot) => [
                (string) ($lot['order'] ?? ''),
                $lot['description'] ?? null,
                F::money($lot['startPrice'] ?? null),
                $withUnit($lot['step'] ?? null, $lot['auctionStepUnit'] ?? null),
                $withUnit($lot['advance'] ?? null, $lot['advanceStepUnit'] ?? null),
                $lot['priceReduction'] ?? null,
                $this->classifierNames($lot),
            ], $lots),
            ['class:center', null, 'align:right', 'align:right', 'align:right', null, null]);
    }

    /**
     * Лоты в результатах торгов, после текста. Сверено с html сообщения 24830540 (торги не состоялись).
     * Победителя и цену выводим, когда увидим образец с состоявшимися торгами
     */
    private function tradeResultLots(array $lots): array
    {
        return $this->grid('lotInfo', [['Номер лота', 'width:50px'], 'Описание', 'Победитель/Покупатель', 'Лучшая цена, руб. / Обоснование', 'Классификация имущества'],
            array_map(function ($lot) {
                // Победитель и покупатель с ценой - из спецификации, не сверено
                $participant = $this->tradeParticipant($lot['winner'] ?? null) ?? $this->tradeParticipant($lot['buyer'] ?? null);

                return [
                    (string) ($lot['order'] ?? ''),
                    $lot['description'] ?? null,
                    F::join([self::LOT_STATUSES[$lot['lotStatus'] ?? ''] ?? null, $this->flatParticipant($participant)], "\n"),
                    F::join([F::money($participant['priceOffer'] ?? null), $lot['basis'] ?? null], "\n"),
                    $this->classifierNames($lot),
                ];
            }, $lots),
            ['align:center', 'align:left', 'align:left', 'align:left', 'align:left'], 'margin-top:10px;');
    }

    /**
     * Участник торгов в победителе / покупателе: {participantPerson: {..}} или {participantCompany: {..}}
     */
    private function tradeParticipant(?array $value): ?array
    {
        $participant = $value['participantPerson'] ?? $value['participantCompany'] ?? null;

        return is_array($participant) && array_filter($participant, fn ($item) => $item !== null && $item !== '') ? $participant : null;
    }

    private function headRows(array $message): array
    {
        return [
            ['№ сообщения', $message['number'] ?? null],
            ['Дата публикации', F::date($message['datePublish'] ?? null)],
        ];
    }

    /**
     * Строки шапки перед № сообщения, свои у некоторых типов
     */
    private function typeHeadRows(?string $type, array $content): array
    {
        return match ($type) {
            // Сверено с html сообщения 24768511
            'ArbitralDecree' => [['Судебный акт', $content['decisionType']['name'] ?? null]],
            default => [],
        };
    }

    private function bankruptRows(array $message): array
    {
        // В content.bankrupt данные подробнее (ФИО по частям, категория), верхний bankrupt - запасной
        $bankrupt = $message['content']['bankrupt'] ?? [];
        $top = $message['bankrupt'] ?? [];
        // К номеру дела Федресурс дописывает код судьи/суда, если он есть ("А11-15103/2023 О.В. Евстигнеева")
        $caseNumber = F::join([
            $message['content']['caseNumber'] ?? $top['legalCaseNumber'] ?? null,
            $message['content']['caseNumberJudgeCode'] ?? null,
        ], ' ');

        if (($bankrupt['type'] ?? $top['type'] ?? null) === 'Person') {
            return [
                ['ФИО должника', F::fio($bankrupt['fio'] ?? null) ?? $top['name'] ?? null],
                ['Дата рождения', F::date($bankrupt['birthdate'] ?? $top['birthdate'] ?? null)],
                ['Место рождения', $bankrupt['birthplace'] ?? $top['birthplace'] ?? null],
                ['Место жительства', $bankrupt['address'] ?? $top['address'] ?? null],
                ['ИНН', $bankrupt['inn'] ?? $top['inn'] ?? null],
                // Так пишет Федресурс, когда СНИЛС не указан
                ['СНИЛС', F::snils($bankrupt['snils'] ?? $top['snils'] ?? null) ?? 'на момент публикации неизвестен'],
                ['ОГРНИП', $bankrupt['ogrnip'] ?? $top['ogrnip'] ?? null],
                // Сверено с html сообщений 24827068, 24830051, 24830593 (везде одно прежнее ФИО; несколько - через запятую)
                ['Ранее имевшиеся ФИО', F::join(array_map(fn ($fio) => F::fio($fio), $bankrupt['fioHistory'] ?? [])) ?? F::join($top['nameHistory'] ?? [])],
                ['№ дела', $caseNumber],
            ];
        }

        // Организация. Сверено с html сообщения 24802175
        return [
            ['Наименование должника', $bankrupt['name'] ?? $bankrupt['fullName'] ?? $top['name'] ?? null],
            ['Адрес', $bankrupt['address'] ?? $top['address'] ?? null],
            ['ОГРН', $bankrupt['ogrn'] ?? $top['ogrn'] ?? null],
            ['ИНН', $bankrupt['inn'] ?? $top['inn'] ?? null],
            ['№ дела', $caseNumber],
        ];
    }

    private function publisherRows(array $message): array
    {
        $publisher = $message['content']['publisher'] ?? [];
        $top = $message['publisher'] ?? [];
        $sro = $publisher['sro'] ?? $top['sroInfo'] ?? [];
        $name = F::fio($publisher['fio'] ?? null) ?? $publisher['name'] ?? $top['name'] ?? null;
        $emailLabel = substr((string) ($message['datePublish'] ?? ''), 0, 10) >= self::EMAIL_LABEL_SINCE ? 'Эл. почта' : 'E-mail';

        if (($publisher['type'] ?? $top['type'] ?? null) === 'ArbitrManager') {
            return [
                ['Арбитражный управляющий', F::withDetails($name, [
                    'ИНН' => $publisher['inn'] ?? $top['inn'] ?? null,
                    'СНИЛС' => F::snils($publisher['snils'] ?? $top['snils'] ?? null),
                ])],
                ['Адрес для корреспонденции', $publisher['correspondenceAddress'] ?? $top['correspondenceAddress'] ?? null],
                [$emailLabel, $publisher['email'] ?? $top['email'] ?? null],
                ['СРО АУ', F::withDetails($sro['name'] ?? null, ['ИНН' => $sro['inn'] ?? null, 'ОГРН' => $sro['ogrn'] ?? null])],
                ['Адрес СРО АУ', $sro['address'] ?? null],
            ];
        }

        // Банк России по кредитным организациям. Сверено с html сообщения 21042046
        if (($publisher['type'] ?? null) === 'CentralBankRf') {
            return [['Контрольный орган', $publisher['name'] ?? $top['name'] ?? null]];
        }

        // Налоговая. Сверено с html сообщения 24796058
        if (($publisher['type'] ?? null) === 'FnsDepartment') {
            $name = $publisher['name'] ?? $top['name'] ?? null;
            return [['Уполномоченный орган', $name ? 'ФНС России в лице ' . $name : null]];
        }

        // СРО АУ как публикатор. Из спецификации, не сверено
        if (($publisher['type'] ?? null) === 'ArbitrManagerSro') {
            return [
                ['СРО АУ', F::withDetails($publisher['name'] ?? $top['name'] ?? null, ['ИНН' => $publisher['inn'] ?? null, 'ОГРН' => $publisher['ogrn'] ?? null])],
                ['Адрес СРО АУ', $publisher['address'] ?? null],
            ];
        }

        // Остальные публикаторы - подпись по типу из спецификации, не сверено.
        // Организация, физлицо, АСВ, оператор ЕФРСБ, внешняя система и неизвестные типы - "Публикатор"
        $label = match ($publisher['type'] ?? null) {
            'FirmTradeOrganizer', 'PersonTradeOrganizer' => 'Организатор торгов',
            'Mfc' => 'МФЦ',
            default => 'Публикатор',
        };

        return [
            [$label, F::withDetails($name, [
                'ИНН' => $publisher['inn'] ?? $top['inn'] ?? null,
                'ОГРНИП' => $publisher['ogrnip'] ?? $top['ogrnip'] ?? null,
                'ОГРН' => $publisher['ogrn'] ?? $top['ogrn'] ?? null,
            ])],
            ['Адрес для корреспонденции', $publisher['correspondenceAddress'] ?? $top['correspondenceAddress'] ?? null],
            [$emailLabel, $publisher['email'] ?? $top['email'] ?? null],
        ];
    }

    /**
     * Строки в конце "Кем опубликовано", свои у некоторых типов: обычно ссылка на связанное сообщение
     */
    private function typePublisherRows(?string $type, array $content, array $message): array
    {
        return match ($type) {
            // Сверено с html сообщения 23786167
            'Rebuttal' => [['Сообщение с опровергаемыми сведениями', $this->messageReference($message, $content['idRebuttedMessage'] ?? null)]],
            // Сверено с html сообщения 24796058
            'AccessionDeclarationSubsidiary' => [['Заявление о привлечении контролирующих лиц', $this->messageReference($message, $content['declarationPersonSubsidiaryMessageId'] ?? null)]],
            // Сверено с html сообщения 24823143
            'CreditorChoiceRightSubsidiary' => [['Сообщение о субсидиарной ответственности', $this->messageReference($message, $content['subsidiaryMessageId'] ?? null)]],
            // Сверено с html сообщений 24830540 и 24817360
            'TradeResult', 'SaleContractResult2' => [['Объявление о проведении торгов', $this->messageReference($message, $content['idAuctionMessage'] ?? null)]],
            // Сверено с html сообщения 24827068
            'Annul' => [['Аннулированное сообщение', $this->messageReference($message, $content['idAnnuledMessage'] ?? null)]],
            default => [],
        };
    }

    /**
     * Требования кредиторов: подзаголовок, по таблице на каждое требование, после каждой - разделитель.
     * Полученные (ReceivingCreditorDemand2, сверено с 22331156) и включенные в реестр (CreditorsDemandRegistered, сверено с 16352657)
     */
    private function creditorDemandsBlocks(array $demands, bool $registered): array
    {
        $blocks = [];
        foreach ($demands as $demand) {
            if ($registered) {
                $sum = $demand['sum'] ?? [];
                $hasSum = isset($sum['mainDebt']) || isset($sum['financialSanctions']) || isset($sum['total']);
                $sub = ['labelStyle' => 'padding-left: 27px;', 'rowClass' => 'odd'];
                // Классы строк у Федресурса: суммы - одной группой odd после заголовка
                $rows = [
                    ['Дата включения в реестр', F::date($demand['dateRegistered'] ?? null), 'rowClass' => 'even'],
                    ['Кредитор', F::participant($demand['creditor'] ?? null), 'rowClass' => 'odd'],
                    // Из спецификации, не сверено
                    ['Сумма заявленных требований, руб.', F::money($demand['sumDeclared'] ?? null), 'rowClass' => 'even'],
                    ...($hasSum ? [['Сумма требований кредитора', null, 'heading' => true, 'rowClass' => 'even']] : []),
                    ['Основной долг, руб.', F::money($sum['mainDebt'] ?? null), ...$sub],
                    ['Финансовые санкции, руб.', F::money($sum['financialSanctions'] ?? null), ...$sub],
                    ['Всего, руб.', F::money($sum['total'] ?? null), ...$sub],
                    ['Основание возникновения', $demand['occurenceReason'] ?? null, 'rowClass' => 'even'],
                    ["Задолженность по заработной плате\nи/или выходному пособию", isset($demand['isDebtSalaryOrSeverance']) ? ($demand['isDebtSalaryOrSeverance'] ? 'Да' : 'Нет') : null, 'rowClass' => 'odd'],
                    ['Очередность удовлетворения', $demand['queue']['description'] ?? null, 'rowClass' => 'even'],
                    ['Обеспечение залогом', $demand['pledgeSecurity']['description'] ?? null, 'rowClass' => 'odd'],
                    // Из спецификации, не сверено
                    ['Сумма требований, обеспеченных залогом, руб.', F::money($demand['pledgeSecuritySum'] ?? null), 'rowClass' => 'even'],
                ];
            } else {
                $rows = [
                    ['Дата получения требований', F::date($demand['dateReceived'] ?? null)],
                    ['Кредитор', F::participant($demand['creditor'] ?? null)],
                    ['Сумма требований кредитора, руб.', F::money($demand['sum'] ?? null)],
                    ['Основание возникновения', $demand['occurenceReason'] ?? null],
                ];
            }
            $blocks[] = $this->rows('bodyInfo', $rows, false, 'width: 800px;');
            $blocks[] = $this->separator();
        }

        return $blocks ? [$this->title('Требования кредиторов', 'msg'), ...$blocks] : [];
    }

    /**
     * Оспариваемые сделки: подзаголовок, по таблице на каждую сделку, после каждой - разделитель.
     * Заявление (DealInvalid2, сверено с 24830291) и результат рассмотрения (DealInvalidResult2, сверено с 24830593).
     * Заявитель не АУ - в образцах не было, не выводим
     */
    private function dealsBlocks(array $deals, array $message, string $title, bool $withResult): array
    {
        $blocks = [];
        foreach ($deals as $deal) {
            $isAu = $deal['isApplicantArbitrManager'] ?? null;
            $price = ($deal['isWithoutPrice'] ?? false) ? 'отсутствует' : F::money($deal['price'] ?? null);

            $blocks[] = $this->rows('bodyInfo', [
                // Ссылка на заявление стоит вне чередования строк
                ["Заявление о признании сделки\nнедействительной", $withResult ? $this->messageReference($message, $deal['dealInvalidMessageNumber'] ?? null) : null, 'rowClass' => 'even'],
                ['Дата подачи заявления', F::date($deal['dateApplication'] ?? null)],
                ["Заявление подано\nарбитражным управляющим", $isAu === null ? null : ($isAu ? 'Да' : 'Нет')],
                // Заявитель не АУ - из спецификации, не сверено
                ['Заявитель', $isAu === true ? 'Арбитражный управляющий' : F::participant($deal['applicant'] ?? null)],
                ['Участники сделки', array_map(fn ($participant) => F::participant($participant), $deal['participants'] ?? []), 'list' => true],
                ['Цена сделки, руб.', $price],
                ["Основание для оспаривания\nсделки", array_map(fn ($reason) => [$reason['type']['description'] ?? null, $reason['comment'] ?? null], $deal['contestingReasons'] ?? []), 'list' => true],
                ['Результат рассмотрения', $withResult ? ($deal['result']['type']['description'] ?? null) : null],
                // Из спецификации, не сверено
                ['Сумма удовлетворения, руб.', $withResult ? F::money($deal['result']['satisfiedSum'] ?? null) : null],
                ['Комментарий', $withResult ? ($deal['result']['comment'] ?? null) : null, true],
            ], false, 'width: 800px;');
            $blocks[] = $this->separator();
        }

        return $blocks ? [$this->title($title, 'msg'), ...$blocks] : [];
    }

    /**
     * Результаты рассмотрения заявлений о привлечении к ответственности: по таблице на лицо, после каждой - разделитель.
     * Сверено с html сообщения 24827143. Размеры ответственности суммой не видели - выводим только "не определено"
     */
    private function responsibilityResultsBlocks(array $persons, array $message, string $title = 'Привлекаемые лица, указанные в заявлении'): array
    {
        $blocks = [];
        foreach ($persons as $person) {
            $result = $person['result'] ?? [];
            $hasResult = !empty($result['resultType']) || !empty($result['responsibilityType']);
            $blocks[] = $this->rows('bodyInfo', [
                ['Заявление о привлечении', $this->messageReference($message, $person['declarationMessageNumber'] ?? null)],
                ['Привлекаемое лицо', F::participant($person['participant'] ?? null)],
                ['Вид ответственности', $person['responsibilityType']['description'] ?? null],
                // Сумма - из спецификации, не сверено
                ["Размер ответственности\n(в заявлении), руб.", $this->bool($person['hasNoAmount'] ?? null) ? 'Невозможно определить на момент подачи заявления' : F::money($person['responsibilityAmount'] ?? null)],
                ...($hasResult ? [['Результаты рассмотрения/пересмотра', null, 'heading' => true, 'labelClass' => 'bold']] : []),
                ['Результаты', $result['resultType']['description'] ?? null],
                ['Вид ответственности', $result['responsibilityType']['description'] ?? null],
                // Сумма и солидарная ответственность - из спецификации, не сверено. "false" у солидарной Федресурс не показывает (24827143)
                ["Размер ответственности\n(в судебном акте), руб.", $this->bool($result['hasNoDataUntilSettlement'] ?? null) ? 'Не определен' : F::money($result['responsibilityAmountCourtDecision'] ?? null)],
                ['Солидарная ответственность', $this->bool($result['jointResponsibility'] ?? null) ? 'Да' : null],
            ], false, 'width: 800px;');
            $blocks[] = $this->separator();
        }

        return $blocks ? [$this->title($title, 'msg'), ...$blocks] : [];
    }

    /**
     * Отчёт оценщика: отчёт и основание, оценщики, объекты оценки, результат экспертизы. Сверено с html сообщения 24828186.
     * Номер и экспертов экспертизы заполненными не видели - не выводим
     */
    private function assessmentReportBlocks(array $content): array
    {
        $report = $content['report'] ?? [];
        $reportText = F::join([
            !empty(trim((string) ($report['number'] ?? ''))) ? 'Номер ' . trim($report['number']) : null,
            !empty($report['date']) ? 'Дата ' . F::date($report['date']) : null,
        ], ' ');
        $withRub = fn ($value) => ($money = F::money($value)) === null ? null : $money . ' руб.';

        return [
            $this->rows('bodyInfo', [
                ['Отчет об оценке', $reportText],
                ['Основание проведения оценки', $content['reason'] ?? null],
            ], false, 'width: 550px;'),
            $this->title('Сведения об оценщиках', 'msg', false),
            $this->grid('personInfo', [['ФИО', 'width:250px'], ['ИНН', 'width:150px'], ['СНИЛС', 'width:150px'], ['СРО', 'width:270px']],
                array_map(fn ($appraiser) => [
                    F::fio($appraiser['fio'] ?? null),
                    $appraiser['inn'] ?? null,
                    $appraiser['snils'] ?? null,
                    // Строки с пробелом в начале, как у Федресурса ("<br> ИНН: ...") - без F::join, он обрезает пробелы
                    implode("\n", array_filter([
                        $appraiser['sro']['name'] ?? null,
                        !empty($appraiser['sro']['inn']) ? ' ИНН: ' . $appraiser['sro']['inn'] : null,
                        !empty($appraiser['sro']['ogrn']) ? ' ОГРН: ' . $appraiser['sro']['ogrn'] : null,
                    ])) ?: null,
                ], $content['appraisers'] ?? []),
                ['align:center', 'align:center', 'align:center', null]),
            $this->title('Сведения об объектах оценки', 'msg', false),
            $this->grid('personInfo', [['Тип', 'width:220px'], ['Описание', 'width:250px'], ["Дата\nопределения\nстоимости", 'width:100px'], ["Стоимость,\nопределенная\nоценщиком", 'width:120px'], ["Балансовая\nстоимость", 'width:120px']],
                array_map(fn ($object) => [
                    $object['classifier']['name'] ?? null,
                    $object['description'] ?? null,
                    F::date($object['dateOfAssessment'] ?? null),
                    // В спецификации MarketValue, в JSON Федресурса - estimatedValue
                    $withRub($object['estimatedValue'] ?? $object['marketValue'] ?? null),
                    $withRub($object['balanceValue'] ?? null),
                ], $content['objectsOfAssessment'] ?? []),
                [null, null, 'align:center', 'align:right', 'align:right']),
            $this->rows('headInfo', [
                // Номер и дата заключения - из спецификации, не сверено
                ['Номер заключения', $content['expertDecision']['number'] ?? null],
                ['Дата заключения', F::date($content['expertDecision']['date'] ?? null)],
                ['Результат экспертизы', $content['expertDecision']['result'] ?? null],
            ], true, 'width: 550px;'),
            // Из спецификации, не сверено: эксперты - как оценщики
            $this->title('Сведения об экспертах', 'msg', false),
            $this->grid('personInfo', [['ФИО', 'width:250px'], ['ИНН', 'width:150px'], ['СНИЛС', 'width:150px'], ['СРО', 'width:270px']],
                array_map(fn ($expert) => [
                    F::fio($expert['fio'] ?? null),
                    $expert['inn'] ?? null,
                    $expert['snils'] ?? null,
                    implode("\n", array_filter([
                        $expert['sro']['name'] ?? null,
                        !empty($expert['sro']['inn']) ? ' ИНН: ' . $expert['sro']['inn'] : null,
                        !empty($expert['sro']['ogrn']) ? ' ОГРН: ' . $expert['sro']['ogrn'] : null,
                    ])) ?: null,
                ], $this->items($content['expertDecision']['experts'] ?? null, 'expert')),
                ['align:center', 'align:center', 'align:center', null]),
        ];
    }

    /**
     * Привлекаемые к ответственности лица: подзаголовок, по таблице на каждое лицо, после каждой - разделитель.
     * Сверено с html сообщения 24828515. Размер ответственности суммой не видели - выводим только "невозможно определить"
     */
    private function responsiblePersonsBlocks(array $persons): array
    {
        $blocks = [];
        foreach ($persons as $person) {
            $blocks[] = $this->rows('bodyInfo', [
                ['Привлекаемое лицо', F::participant($person['participant'] ?? null)],
                ['Вид ответственности', $person['responsibilityType']['description'] ?? null],
                // Сумма - из спецификации, не сверено
                ['Размер ответственности, руб.', $this->bool($person['hasNoAmount'] ?? null) ? 'Невозможно определить на момент подачи заявления' : F::money($person['responsibilityAmount'] ?? null)],
            ], false, 'width: 800px;');
            $blocks[] = $this->separator();
        }

        return $blocks ? [$this->title('Привлекаемые лица', 'msg'), ...$blocks] : [];
    }

    /**
     * Классификация имущества лота: названия через перенос строки (в таблице - <br>)
     */
    private function classifierNames(array $lot): ?string
    {
        $names = array_filter(array_map(fn ($item) => $item['name'] ?? null, $lot['classifierCollection'] ?? []));

        return $names ? implode("\n", $names) : null;
    }

    /**
     * Ссылка на другое сообщение: "№23340023 опубликовано 16.06.2026".
     * Дату берём из additionalInfo.messages или linkedMessages, без неё - только номер
     */
    private function messageReference(array $message, int|string|null $number): ?string
    {
        if ($number === null || $number === '') return null;

        $linked = [...($message['additionalInfo']['messages'] ?? []), ...($message['linkedMessages'] ?? [])];
        foreach ($linked as $item) {
            if ((string) ($item['number'] ?? '') === (string) $number && !empty($item['datePublish'])) {
                return '№' . $number . ' опубликовано ' . F::date($item['datePublish']);
            }
        }

        return '№' . $number;
    }

    /**
     * Публикуемые сведения: только для типов, сверенных с html Федресурса
     */
    private function contentBlocks(?string $type, array $content, array $message): array
    {
        return match ($type) {
            'Meeting2' => $this->meetingBlocks($content),
            'ArbitralDecree' => $this->arbitralDecreeBlocks($content, $message),
            'ExtraordinaryExpenses' => $this->extraordinaryExpensesBlocks($content),
            'ViewExecRestructuringPlan' => $this->viewExecRestructuringPlanBlocks($content),
            // Сверено с html сообщения 24822753
            'ViewDraftRestructuringPlan' => [$this->info([['Место ознакомления: ', $content['placeOfAcquaintance'] ?? null]])],
            // Сверено с html сообщения 23502219
            'CancelDeliberateBankruptcy' => [
                $this->rows('headInfo', [
                    ['Отмененное сообщение', $this->messageReference($message, $content['idCanceledMessage'] ?? null)],
                ], true),
                // Из спецификации, не сверено: абзац как у отмены торгов (24829126)
                $this->msg('Причина отмены:', $content['cancellationReason'] ?? null),
            ],
            // Сверено с html сообщения 23758951
            'FulfilledDemandsRecognitionReview' => [$this->rows('bodyInfo', [
                // Из спецификации, не сверено
                ['Сведения о вынесении судебного акта о признании требований кредиторов удовлетворенными', $this->messageReference($message, $content['fulfilledDemandsRecognitionNumber'] ?? null)],
                ['Дата акта', F::date($content['decisionDate'] ?? null)],
            ], true)],
            // Сверено с html сообщения 24762534
            'IntentionOfDemandsFulfilment' => [$this->rows('bodyInfo', [['Дата акта', F::date($content['decisionDate'] ?? null)]])],
            // Сверено с html сообщения 24210928
            'ChangeCreditorChoiceRightSubsidiary' => [$this->rows('bodyInfo', [
                ['Сообщение о праве кредитора выбрать способ распоряжения правом требования о привлечении к субсидиарной ответственности', $this->messageReference($message, $content['creditorChoiceRightSubsidiaryNumber'] ?? null)],
            ])],
            // Сверено с html сообщения 24785898
            'MortgageSaleExclusion' => [$this->rows('bodyInfo', [
                ['Исключено из конкурсной массы, руб.', F::money($content['exclusionAmount'] ?? null)],
            ], true, 'width: 450px;')],
            // Сверено с html сообщений 24613420 и 23516807
            'FulfilledDemandsRecognition', 'IntentionOfDemandsFulfilmentReview' => [$this->rows('bodyInfo', [
                ['Сведения о вынесении судебного акта об удовлетворении заявления о намерении удовлетворить требования кредиторов к должнику', $this->messageReference($message, $content['intentionOfDemandsFulfilmentNumber'] ?? null)],
                ['Дата акта', F::date($content['decisionDate'] ?? null)],
            ])],
            // Сверено с html сообщения 23453038
            'PartBuildMonetaryClaim' => [$this->rows('bodyInfo', [
                ["Арбитражный суд,\nрассматривающий дело о банкротстве", $content['arbitralCourt'] ?? null],
                ['Последствия не предъявления требований', $content['consequences'] ?? null, true],
            ], null)],
            // Сверено с html сообщения 24823143
            'CreditorChoiceRightSubsidiary' => [$this->rows('headInfo', [
                ['Дата принятия акта о доказанности наличия основания или о субсидиарной ответственности', F::date($content['subsidiaryActDate'] ?? null)],
            ], false, 'width: 700px;')],
            // Сверено с html сообщения 24829126
            'CancelAuctionTradeResult' => [
                $this->rows('bodyInfo', [['Отмененное сообщение', $this->messageReference($message, $content['idCanceledMessage'] ?? null)]]),
                $this->msg('Причина отмены:', $content['cancellationReason'] ?? null),
            ],
            // Сверено с html сообщений 24830653 и 24826909 (изменение - плюс ссылка на измененное сообщение)
            'DeliberateBankruptcy', 'ChangeDeliberateBankruptcy' => [$this->rows('bodyInfo', [
                ['Признаки преднамеренного банкротства', self::BANKRUPTCY_SIGNS[$content['deliberateBankruptcySigns'] ?? ''] ?? null],
                // Причины - из спецификации, не сверено
                ['Причина отказа проведения проверки', $content['deliberateSignsNotSearchedReason'] ?? null],
                ['Признаки фиктивного банкротства', self::BANKRUPTCY_SIGNS[$content['fakeBankruptcySigns'] ?? ''] ?? null],
                ['Причина отказа проведения проверки', $content['fakeSignsNotSearchedReason'] ?? null],
                ['Измененное сообщение', $this->messageReference($message, $content['idChangedMessage'] ?? null), 'labelClass' => 'primary'],
            ], false, null, 'primary-long')],
            // Сверено с html сообщения 24829740. Вариант "собрание проведено не АУ" не видели - не выводим
            'MeetingWorkerResult' => [
                $this->msg('', ($content['isLeadByArbitrManager'] ?? null) === true ? 'Собрание проведено арбитражным управляющим' : null),
                $this->rows('bodyInfo', [
                    // Из спецификации, не сверено
                    ['Дата получения сведений о проведении собрания', F::date($content['meetingNoticeDate'] ?? null)],
                    ['Дата проведения собрания', F::date($content['meetingDate'] ?? null)],
                    ["Количество присутствовавших работников\n(бывших работников)", isset($content['workersCount']) ? (string) $content['workersCount'] : null, 'valueClass' => 'money'],
                    ['Сумма требований второй очереди, руб.', F::money($content['requirementSumm'] ?? null), 'valueClass' => 'money'],
                ]),
            ],
            // Сверено с html сообщения 24829977: как собрание кредиторов, без формы и ознакомления
            'Committee2' => $this->meetingBlocks($content),
            // Сверено с html сообщения 24828254. Повестка здесь с переносами строк
            'MeetingWorker2' => [$this->rows('bodyInfo', [
                ['Форма проведения собрания', $content['meetingForm'] ?? null],
                ['Дата и время начала собрания', F::dateTimeWithZone($content['meetingDateTime'] ?? null)],
                ['Место проведения', $content['meetingSite'] ?? null],
                ['Дата и время начала регистрации', F::dateTimeWithZone($content['registrationDateTimeBegin'] ?? null)],
                ['Дата и время окончания регистрации', F::dateTimeWithZone($content['registrationDateTimeEnd'] ?? null)],
                ['Место регистрации', $content['registrationSite'] ?? null],
                // Заочное голосование - из спецификации, не сверено
                ['Дата окончания приема бюллетеней', F::date($content['ballotsReceptionEndDate'] ?? null)],
                ['Почтовый адрес для направления бюллетеней', $content['ballotsSendPostAddress'] ?? null],
                ['Повестка', $content['notice'] ?? null, true],
            ])],
            // Сверено с html сообщения 24827068
            'Annul' => [$this->msg('Причина аннулирования:', $content['lockAnnuledMessageReason'] ?? null)],
            // Сверено с html сообщения 24827143. Группу supervisoryPersonsAdditional не видели заполненной - не выводим
            'PersonResponsibilityResult' => [
                $this->title('Сведения о судебном акте'),
                $this->rows('bodyInfo', [
                    ['Номер дела', $content['act']['legalCaseNumber'] ?? null],
                    ['Дата вынесения', F::date($content['act']['decisionDate'] ?? null)],
                ], false, 'width: 800px;'),
                ...$this->responsibilityResultsBlocks($content['supervisoryPersonsFromDeclarations'] ?? [], $message),
                // Из спецификации, не сверено
                ...$this->responsibilityResultsBlocks($this->items($content['supervisoryPersonsAdditional'] ?? null, 'supervisoryPerson'), $message, 'Привлекаемые лица, не указанные в заявлении'),
            ],
            // Сверено с html сообщения 24828186
            'AssessmentReport' => $this->assessmentReportBlocks($content),
            'SaleContractResult2' => $this->saleContractBlocks($content),
            'TransferOwnershipRealEstate' => $this->transferOwnershipBlocks($content),
            'Auction2' => $this->auctionBlocks($content, $message, false),
            'ChangeAuction2' => $this->auctionBlocks($content, $message, true),
            'DealInvalid2' => $this->dealsBlocks($content['deals'] ?? [], $message, 'Сделка', false),
            'DealInvalidResult2' => [
                $this->title('Сведения о судебном акте'),
                $this->rows('bodyInfo', [
                    ['Номер дела', $content['act']['legalCaseNumber'] ?? null],
                    ['Дата вынесения', F::date($content['act']['decisionDate'] ?? null)],
                ], false, 'width: 800px;'),
                ...$this->dealsBlocks($content['deals'] ?? [], $message, 'Сделки', true),
            ],
            'ReceivingCreditorDemand2' => $this->creditorDemandsBlocks($content['demands'] ?? [], false),
            'CreditorsDemandRegistered' => $this->creditorDemandsBlocks($content['demands'] ?? [], true),
            // Сверено с html сообщения 24830051
            'BankOpenAccountDebtor' => [$this->rows('bodyInfo', [
                ['Наименование', $content['name'] ?? null],
                ['ИНН', $content['inn'] ?? null],
                ['ОГРН', $content['ogrn'] ?? null],
                // Из спецификации, не сверено
                ['БИК', $content['bik'] ?? null],
            ])],
            // Сверено с html сообщения 24394239: как собрание кредиторов плюс порядок ознакомления и регистрации
            'MeetingParticipantsBuilding2' => [$this->rows('bodyInfo', [
                ...array_slice($this->meetingBlocks($content)[0]['rows'], 0, 1),
                // Из спецификации, не сверено
                ['Порядок проведения собрания', $content['orderOfMeeting'] ?? null],
                ...array_slice($this->meetingBlocks($content)[0]['rows'], 1),
                ['Порядок ознакомления с материалами', $content['materialsFamiliarizationOrder'] ?? null],
                ['Порядок регистрации участников собрания', $content['membersRegistrationOrder'] ?? null],
            ])],
            // Сверено с html сообщения 24828515
            'PersonResponsibilityDeclaration' => [
                $this->rows('bodyInfo', [['Дата подачи заявления', F::date($content['dateApplication'] ?? null)]]),
                ...$this->responsiblePersonsBlocks($content['supervisoryPersons'] ?? []),
            ],
            // Сверено с html сообщения 20324867. СНИЛС у Федресурса здесь без форматирования
            'DisqualificationArbitrationManager2' => [$this->rows('bodyInfo', [
                ['Арбитражный управляющий', F::withDetails($content['arbitrManager']['fio'] ?? null, [
                    'ИНН' => $content['arbitrManager']['inn'] ?? null,
                    'СНИЛС' => $content['arbitrManager']['snils'] ?? null,
                ])],
                ['Арбитражный суд, вынесший решение', $content['court']['name'] ?? null],
                ['Причина дисквалификации', $content['reason'] ?? null],
                ['Дата начала дисквалификации', F::date($content['dateBegin'] ?? null)],
                ['Срок дисквалификации', F::duration($content['duration'] ?? null)],
            ])],
            // Сверено с html сообщения 24695224
            'ApplicationReviewCourtDecision' => [
                $this->rows('bodyInfo', [['Дата подачи заявления', F::date($content['filingApplicationDate'] ?? null)]]),
                $this->title('Сведения о судебном акте'),
                $this->rows('bodyInfo', [
                    ['Дата вынесения', F::date($content['courtDecisionReviewInfo']['decisionDate'] ?? null)],
                    ['Номер дела', $content['courtDecisionReviewInfo']['legalCaseNumber'] ?? null],
                    ['Суд', $content['courtDecisionReviewInfo']['courtName'] ?? null],
                ]),
            ],
            // Сверено с html сообщения 24780011
            'ProcedureGrantingIndemnity' => [$this->rows('bodyInfo', [
                ['Имущество, предлагаемое в качестве отступного', $content['propertyIndemnityOffer'] ?? null],
                ['Порядок ознакомления с имуществом', $content['propertyFamiliarizationProcedure'] ?? null],
                ['Срок направления заявлений о согласии', $content['consestApplicationPeriod'] ?? null],
            ])],
            // Сверено с html сообщений 24759837 и 24826345
            'SaleOrderPledgedProperty2' => [
                $this->grid('lotInfo', [['Номер лота', 'width:15%'], ['Описание', 'width:35%'], ['Начальная цена, руб', 'width:15px'], ['Классификация имущества', 'width:35%']],
                    array_map(fn ($lot) => [
                        (string) ($lot['order'] ?? ''),
                        $lot['description'] ?? null,
                        F::money($lot['startPrice'] ?? null),
                        $this->classifierNames($lot),
                    ], $content['lotTable'] ?? []),
                    ['class:center', null, 'align:right', null]),
                $this->msg('Условия обеспечения сохранности предмета залога:', $content['additionalText'] ?? null),
            ],
            default => $this->specContentBlocks($type, $content, $message),
        };
    }

    /**
     * Сообщение о собрании кредиторов. Сверено с html сообщений 13709791 (очное) и 24830314 (заочное)
     */
    private function meetingBlocks(array $content): array
    {
        $examinationDate = F::date($content['examinationDate'] ?? null);
        $examinationDateEnd = F::date($content['examinationDateEnd'] ?? null);
        // Заочное собрание: дата собрания - она же окончание приёма бюллетеней
        $isAbsentee = ($content['meetingForm'] ?? null) === 'Заочная';

        return [$this->rows('bodyInfo', [
            ['Форма проведения', $content['meetingForm'] ?? null],
            [$isAbsentee ? 'Дата и время начала собрания (дата окончания приема бюллетеней)' : 'Дата и время начала собрания', F::dateTimeWithZone($content['meetingDateTime'] ?? null)],
            ['Место проведения', $content['meetingSite'] ?? null],
            ['Дата и время начала регистрации', F::dateTimeWithZone($content['registrationDateTimeBegin'] ?? null)],
            ['Дата и время окончания регистрации', F::dateTimeWithZone($content['registrationDateTimeEnd'] ?? null)],
            ['Место регистрации', $content['registrationSite'] ?? null],
            // У Федресурса "c" латиницей, повторяем как есть
            ['Дата ознакомления', F::join([$examinationDate ? 'c ' . $examinationDate : null, $examinationDateEnd ? 'по ' . $examinationDateEnd : null], ' ')],
            ['Место ознакомления', $content['examinationSite'] ?? null],
            ['Повестка', $content['notice'] ?? null],
            // Из спецификации, не сверено
            ['Веб-адрес для проведения электронного собрания', $content['webAddress'] ?? null],
            ['Почтовый адрес финансового управляющего для направления заполненных бюллетеней', $content['fuMailAddress'] ?? null],
        ])];
    }

    /**
     * Сообщение о судебном акте. Сверено с html сообщения 24768511
     */
    private function arbitralDecreeBlocks(array $content, array $message): array
    {
        $decree = $content['courtDecree'] ?? [];
        $prolongation = $content['procedureProlongation'] ?? [];
        $discharge = $content['dischargeFromObligations'] ?? null;
        $cancelled = array_map(
            fn ($item) => $this->messageReference($message, is_array($item) ? ($item['number'] ?? null) : $item),
            $this->items($content['cancelledMessages'] ?? null, 'number'),
        );
        $isDischarged = $this->bool($discharge['isDischarged'] ?? null);

        return [
            $this->grid('courtInfo', ['Суд', '№', 'Дата акта'], [
                [$decree['courtName'] ?? null, $decree['fileNumber'] ?? null, F::date($decree['decisionDate'] ?? null)],
            ], [null, 'class:center', 'class:center']),
            $this->field('Тип арбитражного управляющего', self::ARBITR_MANAGER_TYPES[$content['arbitrManagerType'] ?? ''] ?? null),
            // Дальше до "Дата следующего судебного заседания" - из спецификации, не сверено
            $this->field('Арбитражный управляющий', $this->flatParticipant($content['arbitrManagerInfo'] ?? null)),
            $this->field('Основание прекращения производства по делу', $content['legalCaseTerminationType']['description'] ?? null),
            $this->field('Продление до даты', F::date($prolongation['date'] ?? null)),
            $this->field('Продление на количество месяцев', isset($prolongation['months']) && $prolongation['months'] !== '' ? (string) $prolongation['months'] : null),
            $this->field('Продляемый судебный акт', $this->messageReference($message, $prolongation['messageNumber'] ?? null)),
            $this->field('Измененный судебный акт', $this->messageReference($message, $content['changedMessageNumber'] ?? null)),
            $this->field('Отмененные судебные акты', F::join($cancelled)),
            $this->field('Сумма убытков, причиненных арбитражным управляющим, руб.', F::money($content['lossesFromArbitrManagerActionsAmount'] ?? null)),
            $this->field('Тип незаконного действия', $content['arbitrManagerIllegalActionType'] ?? null),
            $this->field('Решение принято в связи с отменой плана реструктуризации долгов гражданина', $this->yesNo($content['decisionMadeDueTorCancellationRestructuringPlan'] ?? null)),
            $this->field('Причина отмены плана реструктуризации', $content['reasonForCancellationRestructuringPlan'] ?? null),
            $this->field('Дата закрытия реестра требований кредиторов', F::date($content['creditorClaimRegisterCloseDate'] ?? null)),
            $this->field('Дата истечения срока установления требований кредиторов', F::date($content['creditorClaimSettingRequirementsExpirationDate'] ?? null)),
            // Использовался до 18.07.2023, потом - dischargeFromObligations
            $this->field('Не применять в отношении гражданина правило об освобождении от исполнения обязательств', $this->yesNo($content['citizenNotReleasedFromResponsibility'] ?? null)),
            $this->field('Освобождение гражданина от обязательств', $isDischarged === null ? null : ($isDischarged ? 'Применяется' : 'Не применяется')),
            $this->field('Причина неприменения правила об освобождении гражданина от обязательств', $discharge['reason'] ?? null),
            $this->field('Дата следующего судебного заседания', F::date($content['nextCourtSessionDate'] ?? null)),
            // Таблица последней: подзаголовок перед пустой таблицей иначе останется (cleanBlocks смотрит на блоки после него)
            $this->title('Требования кредиторов, на которые освобождение от обязательств не распространяется', 'msg'),
            $this->grid('personInfo', ['Кредитор', 'Основание', 'Сумма требований, руб.'],
                array_map(fn ($requirement) => [
                    $this->flatParticipant($requirement['creditor'] ?? null),
                    $requirement['cause'] ?? null,
                    F::money($requirement['sum'] ?? null),
                ], $this->items($discharge['creditorRequirments'] ?? null, 'creditorRequirment')),
                [null, null, 'align:right']),
        ];
    }

    /**
     * Ознакомление с отчётом о результатах исполнения плана реструктуризации. Сверено с html сообщения 24798444
     */
    private function viewExecRestructuringPlanBlocks(array $content): array
    {
        $notSatisfied = $content['includedRegistryRequirmentsNotSatisfied'] ?? null;

        $notSatisfied = $this->bool($notSatisfied);

        return [$this->info([
            // Подпись сверена (в 24798444 без значения), формат даты - из спецификации, не сверено
            ['Дата окончания исполнения плана реструктуризации: ', F::date($content['dateEndRestructuringPlanExecution'] ?? null)],
            ['Место ознакомления: ', $content['placeOfAcquaintance'] ?? null],
            // Вариант false сверен, true - из спецификации ("не удовлетворены"), не сверено
            ['', match ($notSatisfied) {
                false => 'Требования кредиторов, включенные в план реструктуризации долгов гражданина, на дату рассмотрения отчета удовлетворены полностью.',
                true => 'Требования кредиторов, включенные в план реструктуризации долгов гражданина, на дату рассмотрения отчета не удовлетворены.',
                null => null,
            }],
        ])];
    }

    /**
     * Сведения об осуществлении внеочередных расходов. Сверено с html сообщения 24802175
     */
    private function extraordinaryExpensesBlocks(array $content): array
    {
        return [$this->rows('headInfo', [
            ['Кредитор', F::participant($content['creditor'] ?? null)],
            ['Предполагаемый размер расходов, руб.', F::money($content['estimatedAmountOfExpenses'] ?? null)],
        ])];
    }
}
