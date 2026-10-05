{{-- Блоки сообщения ЕФРСБ: rows, grid, field, info, msg. Описание - в EfrsbMessageRenderer --}}
@php
    $br = fn ($value) => str_replace(["\r\n", "\n", "\r"], '<br>', e($value));
    $rowClass = fn ($index, $firstOdd) => $firstOdd === null ? null : (($index + ($firstOdd ? 1 : 0)) % 2 ? 'odd' : 'even');
    // Выравнивание столбца: "class:center" -> class="center", "align:right" -> align="right"
    $cellAttr = function (?string $align) {
        if (!$align) return '';
        [$kind, $value] = explode(':', $align, 2);
        return $kind === 'class' ? ' class="' . e($value) . '"' : ' align="' . e($value) . '"';
    };
@endphp
@foreach ($blocks as $block)
    @if ($block['type'] === 'grid')
        <table class="{{ $block['class'] }}" width="100%" cellspacing="0" cellpadding="0" border="0" @if ($block['style']) style="{{ $block['style'] }}" @endif>
            <tbody>
                <tr>
                    @foreach ($block['head'] as [$head, $headStyle])
                        <th @if ($headStyle) style="{{ $headStyle }}" @endif>{!! $br($head) !!}</th>
                    @endforeach
                </tr>
                @foreach ($block['rows'] as $row)
                    <tr @if ($block['rowClass']) class="{{ $block['rowClass'] }}" @endif>
                        @foreach ($row as $cell)
                            <td{!! $cellAttr($block['align'][$loop->index] ?? null) !!}>{!! $br($cell) !!}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @elseif ($block['type'] === 'title')
        <br><div class="{{ $block['class'] }}">@if ($block['bold'])<b>{{ $block['rows'][0][0] }}</b>@else{{ $block['rows'][0][0] }}@endif</div><br>
    @elseif ($block['type'] === 'separator')
        <div class="msg" style="width: 800px; padding-top: 10px; padding-bottom: 9px;"><hr></div>
    @elseif ($block['type'] === 'msg')
        @foreach ($block['rows'] as $row)
            @if ($row[0] !== '')
                <p></p>
                <div class="msg"><b>{{ $row[0] }}</b><br>{!! $br($row[1]) !!}</div>
                <p></p>
            @else
                {{-- Абзац без подписи ("Собрание проведено арбитражным управляющим") --}}
                <div class="msg">{!! $br($row[1]) !!}</div><br>
            @endif
        @endforeach
    @elseif ($block['type'] === 'files')
        {{-- Прикрепленные документы: подпись и список имен файлов, как под сообщением у Федресурса --}}
        @foreach ($block['rows'] as $row)
            <p></p>
            <div class="msg"><b>{{ $row[0] }}</b>
                <ul class="files">
                    @foreach ($row[1] as $file)
                        <li>{{ $file }}</li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    @elseif ($block['type'] === 'info')
        @foreach ($block['rows'] as $row)
            @if (!$loop->first)<br>@endif
            <div class="AdditionalInfo">{{ $row[0] }}{{ $row[1] }}</div>
        @endforeach
    @elseif ($block['type'] === 'field')
        <br>
        <table class="table-without-border">
            <tbody>
                @foreach ($block['rows'] as $row)
                    <tr>
                        <td class="primary">{{ $row[0] }}</td>
                        <td>{{ $row[1] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <table class="{{ $block['class'] }}" cellspacing="0" cellpadding="3" border="0" @if ($block['style']) style="{{ $block['style'] }}" @endif>
            <tbody>
                {{-- Чередование считаем только по строкам без явного rowClass --}}
                @php($alternate = 0)
                @foreach ($block['rows'] as $row)
                    <tr @if ($class = $row['rowClass'] ?? $rowClass($alternate++, $block['firstOdd'])) class="{{ $class }}" @endif>
                        @if ($row['hr'] ?? false)
                            {{-- Разделитель между элементами списка внутри одной таблицы (договоры) --}}
                            <td colspan="2"><hr></td>
                        @elseif ($row['heading'] ?? false)
                            {{-- Строка-заголовок группы на всю ширину ("Сумма требований кредитора") --}}
                            <td colspan="2" class="{{ $row['labelClass'] ?? $block['labelClass'] }}">{!! $br($row[0]) !!}</td>
                        @elseif ($row['list'] ?? false)
                            {{-- Список внутри ячейки (участники сделки, основания): элемент - строка или [жирный заголовок, текст] --}}
                            <td class="{{ $row['labelClass'] ?? $block['labelClass'] }}" valign="top">{!! $br($row[0]) !!}</td>
                            <td style="padding: 0;">
                                <table class="headInfo" style="margin-left: 0px; min-height: 33px;">
                                    <tbody>
                                        @foreach ($row[1] as $item)
                                            <tr class="odd">
                                                <td style="padding-top: 4px; padding-bottom: 4px;">@if (is_array($item))<b>{{ $item[0] }}</b>@if (($item[1] ?? '') !== '')<br>{!! $br($item[1]) !!}@endif @else{{ $item }}@endif</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </td>
                        @else
                            <td class="{{ $row['labelClass'] ?? $block['labelClass'] }}" @if (!empty($row['labelStyle'])) style="{{ $row['labelStyle'] }}" @endif>{!! $br($row[0]) !!}</td>
                            {{-- Значение с переносами (третий элемент строки true) - переносы в <br>, иначе одной строкой --}}
                            <td @if (!empty($row['valueClass'])) class="{{ $row['valueClass'] }}" @endif>@if ($row[2] ?? false){!! $br($row[1]) !!}@else{{ $row[1] }}@endif</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endforeach
