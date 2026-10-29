{{--
    Bouton standard réutilisable : primary | secondary | danger | ghost.
    Tailles : sm | md | lg. Hauteur tactile 44px min pour md/lg.
    Micro-interactions actives, focus-visible accessible, support de l'état loading.
--}}
@props(['variant' => 'primary', 'size' => 'md', 'href' => null, 'type' => 'button', 'loading' => false])

@php
$styles = [
    'primary' => 'bg-primary-800 text-white hover:bg-primary-700 active:bg-primary-900 shadow-sm hover:shadow focus-visible:ring-primary-600',
    'secondary' => 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 hover:text-slate-900 active:bg-slate-100 shadow-xs hover:shadow-sm focus-visible:ring-slate-400',
    'danger' => 'bg-rose-700 text-white hover:bg-rose-600 active:bg-rose-800 shadow-sm hover:shadow focus-visible:ring-rose-500',
    'ghost' => 'text-primary-800 hover:bg-primary-50/80 active:bg-primary-100/60 underline underline-offset-2 focus-visible:ring-primary-600',
];

$tailles = [
    'sm' => 'px-3 py-1.5 text-xs rounded-lg min-h-[36px]',
    'md' => 'px-5 py-2.5 text-sm rounded-xl min-h-[44px]',
    'lg' => 'px-6 py-3 text-base rounded-2xl min-h-[48px]',
];

$classe = 'inline-flex items-center justify-center gap-2 font-bold transition-all duration-150 active:scale-[0.98] focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:opacity-60 disabled:cursor-not-allowed disabled:active:scale-100 ' . ($tailles[$size] ?? $tailles['md']) . ' ' . ($styles[$variant] ?? $styles['primary']);

$alpineLoadingExpr = $attributes->get(':loading') ?? $attributes->get('x-loading');
$cleanAttributes = $alpineLoadingExpr ? $attributes->except([':loading', 'x-loading']) : $attributes;
@endphp

@if($href !== null)
    <a href="{{ $href }}" {{ $cleanAttributes->merge(['class' => $classe]) }}>
        @if($loading)
            <svg class="h-4 w-4 animate-spin shrink-0 text-current opacity-80" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
        @endif
        @if($alpineLoadingExpr)
            <svg x-show="{{ $alpineLoadingExpr }}" x-cloak class="h-4 w-4 animate-spin shrink-0 text-current opacity-80" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
        @endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $cleanAttributes->merge(['class' => $classe]) }}
        @if($loading) disabled aria-busy="true" @endif
        @if($alpineLoadingExpr) :disabled="{{ $alpineLoadingExpr }}" :aria-busy="Boolean({{ $alpineLoadingExpr }}).toString()" @endif>
        @if($loading)
            <svg class="h-4 w-4 animate-spin shrink-0 text-current opacity-80" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
        @endif
        @if($alpineLoadingExpr)
            <svg x-show="{{ $alpineLoadingExpr }}" x-cloak class="h-4 w-4 animate-spin shrink-0 text-current opacity-80" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
        @endif
        {{ $slot }}
    </button>
@endif
