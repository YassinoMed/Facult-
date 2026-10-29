{{--
    Modale accessible : titre, explication, Escape, clic extérieur, focus initial.
    Supporte taille (md, lg, xl, 2xl), transition scale, backdrop blur et adaptation mobile.
    Usage : <x-modal titre="…" nom="ouvert" taille="lg">…<x-slot:actions>…</x-slot:actions></x-modal>
--}}
@props(['titre', 'nom' => 'ouvert', 'taille' => 'md'])

@php
$tailles = [
    'md' => 'max-w-md',
    'lg' => 'max-w-lg',
    'xl' => 'max-w-xl',
    '2xl' => 'max-w-2xl',
];
$classeTaille = $tailles[$taille] ?? 'max-w-md';
@endphp

<div x-show="{{ $nom }}" x-cloak
     @keydown.escape.window="{{ $nom }} = false"
     x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
     {{ $attributes->merge(['class' => 'fixed inset-0 z-[70] flex items-center justify-center p-4 max-sm:p-0 max-sm:items-end']) }} role="dialog" aria-modal="true" aria-label="{{ $titre }}">
    <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-xs transition-opacity" @click="{{ $nom }} = false" aria-hidden="true"></div>
    <div x-trap.noscroll="{{ $nom }}"
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95 max-sm:translate-y-4" x-transition:enter-end="opacity-100 scale-100 max-sm:translate-y-0"
         x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 scale-100 max-sm:translate-y-0" x-transition:leave-end="opacity-0 scale-95 max-sm:translate-y-4"
         class="relative w-full {{ $classeTaille }} rounded-2xl bg-white p-6 shadow-2xl max-sm:rounded-b-none border border-slate-200/80">
        <div class="flex items-start justify-between gap-3 pr-8">
            <h2 class="text-base font-black tracking-tight text-slate-900">{{ $titre }}</h2>
        </div>
        <div class="mt-2.5 text-sm text-slate-600 leading-relaxed">{{ $slot }}</div>
        @if(isset($actions))
            <div class="mt-6 flex flex-col-reverse sm:flex-row sm:justify-end gap-2.5 pt-3 border-t border-slate-100">{{ $actions }}</div>
        @endif
        <button type="button" @click="{{ $nom }} = false" aria-label="Fermer la modale"
                class="absolute top-3.5 right-3.5 rounded-xl p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700 min-h-[44px] min-w-[44px] flex items-center justify-center transition-colors">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
            </svg>
        </button>
    </div>
</div>
