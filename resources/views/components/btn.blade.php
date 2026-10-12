{{--
    Bouton standard (§35) : primary | secondary | danger | ghost.
    Hauteur tactile 44px minimum, focus visible global.
--}}
@props(['variant' => 'primary', 'href' => null, 'type' => 'button'])

@php
$styles = [
    'primary' => 'bg-primary-800 text-white hover:bg-primary-700 shadow-sm',
    'secondary' => 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50',
    'danger' => 'bg-rose-700 text-white hover:bg-rose-600 shadow-sm',
    'ghost' => 'text-primary-800 hover:bg-primary-50 underline underline-offset-2',
];
$classe = 'inline-flex items-center justify-center gap-2 rounded-xl px-5 py-2.5 text-sm font-bold transition focus:outline-none min-h-[44px] disabled:opacity-60 disabled:cursor-not-allowed ' . ($styles[$variant] ?? $styles['primary']);
@endphp

@if($href !== null)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classe]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classe]) }}>{{ $slot }}</button>
@endif
