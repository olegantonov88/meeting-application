{{-- Разметка и CSS как у html-сообщений Федресурса. Данные готовит App\Services\EfrsbMessageRender\EfrsbMessageRenderer --}}
@php
    // Перенос строки в подписи или значении -> <br>, как у Федресурса
    $br = fn ($value) => str_replace(["\r\n", "\n", "\r"], '<br>', e($value));
@endphp
<div class="containerInfo">
    {{-- Объединённый CSS образцов Федресурса: у них он свой у каждого типа, берём самые частые значения --}}
    <style type="text/css">
        div.containerInfo { color: #333333; font-family: Tahoma, Sans Serif; font-size: 70%; }
        div.containerInfo table tr td { border: Solid 1px #eaf1f7; }
        div.containerInfo table { border-collapse: collapse; }
        div.containerInfo h1.red { color: #c82a10; font-size: 130%; font-weight: bold; margin-bottom: 10px; margin-top: 0; }
        div.containerInfo table.headInfo, div.containerInfo table.bodyInfo { font-size: 100%; width: 100%; margin-left: 10px; }
        div.containerInfo tr.odd { background-color: White; }
        div.containerInfo tr.even { background-color: #f3f6f8; }
        div.AdditionalInfo { background-color: #f3f6f8; font-weight: bold; }
        div.containerInfo td { padding: 4px 10px; }
        div.containerInfo th { font-weight: bold; padding: 4px 10px; text-align: center; vertical-align: middle; }
        div.containerInfo td.primary { font-weight: bold; white-space: nowrap; width: 202px; }
        div.containerInfo td.primary-long { font-weight: bold; white-space: normal; width: 202px; }
        div.containerInfo td.primary-participant { font-weight: bold; white-space: normal; width: 202px; }
        div.containerInfo td.primary2 { width: 500px; font-weight: bold; white-space: nowrap; }
        div.containerInfo td.center { text-align: center; }
        td.money { text-align: right; }
        div.containerInfo td.sublevel { padding-left: 20px; }
        div.containerInfo td.title { font-weight: bold; white-space: nowrap; }
        div.containerInfo td.contractInfo { width: 50%; }
        div.containerInfo td.block { padding-top: 10px; }
        div.containerInfo table.AdditionalInfo { background-color: #ccd8e3; margin-left: 10px; }
        div.containerInfo table.AdditionalInfo tr { border-style: none; }
        div.containerInfo table.courtInfo { background-color: #ccd8e3; font-size: 100%; margin-left: 10px; }
        div.containerInfo table.lotInfo { font-size: 100%; background-color: #ccd8e3; margin-left: 10px; }
        div.containerInfo table.personInfo { font-size: 100%; background-color: #ccd8e3; width: 100%; margin-left: 20px; }
        div.containerInfo table.personInfo th, div.containerInfo table.personInfo td { padding: 5px; }
        div.containerInfo a.Reference { color: Blue; font-family: Tahoma, Sans Serif; font-size: 100%; }
        div.containerInfo p.msg { margin: 0; padding: 0 0 10px; text-align: justify; }
        div.containerInfo div.msg { margin-left: 10px; }
        div.containerInfo div.msg table { border-collapse: collapse; border: Solid 1px Black; }
        div.containerInfo div.msg table tr { border-collapse: collapse; border: Solid 1px Black; }
        div.containerInfo div.msg table td { border-collapse: collapse; border: Solid 1px Black; padding: 4px 10px; }
        div.containerInfo span.red-text { color: red; }
        div.containerInfo ul.files { margin: 4px 0 0; padding-left: 20px; }
        .level-1 { margin-left: 10px; }
        .table-without-border { border-style: none !important; }
        .table-without-border td { border-style: none !important; }
        .table-without-border tr { border-style: none !important; }
    </style>

    @foreach ($sections as $section)
        @if ($section['title'])
            <p></p>
            <div class=""><b>{{ $section['title'] }}</b></div>
            <p></p>
        @endif
        @include('efrsb-message-render.blocks', ['blocks' => $section['blocks']])
    @endforeach

    @if ($text)
        <p></p>
        {{-- Переносы строк как у Федресурса: <br> без перевода строки --}}
        <div class="msg"><b>Текст:</b><br>{!! $br($text) !!}</div>
        <p></p>
    @endif

    {{-- После текста: у некоторых типов (лоты в результатах торгов и т.п.) --}}
    @if ($afterText)
        <br>
        @include('efrsb-message-render.blocks', ['blocks' => $afterText])
    @endif
</div>
