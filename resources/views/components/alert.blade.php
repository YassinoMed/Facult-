{{--
    Alerte sémantique : icône + texte, jamais la couleur seule.
    Types : success | warning | error | danger | info.
    Supporte l'option dismissible via x-data.
--}}
@props(['type' => 'info', 'titre' => null, 'message' => null, 'dismissible' => false])

@php
$typeNormalise = ($type === 'danger') ? 'error' : $type;
$styles = [
    'success' => ['border-emerald-200', 'bg-emerald-50/90', 'text-emerald-950', 'text-emerald-600'],
    'warning' => ['border-amber-200', 'bg-amber-50/90', 'text-amber-950', 'text-amber-600'],
    'error' => ['border-rose-200', 'bg-rose-50/90', 'text-rose-950', 'text-rose-600'],
    'info' => ['border-sky-200', 'bg-sky-50/90', 'text-sky-950', 'text-sky-600'],
];
[$bordure, $fond, $texte, $couleurIcone] = $styles[$typeNormalise] ?? $styles['info'];
@endphp

<div x-data="{ fermee: false }" x-show="!fermee" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
     role="{{ $typeNormalise === 'error' ? 'alert' : 'status' }}"
     {{ $attributes->merge(['class' => "rounded-2xl border {$bordure} {$fond} px-4 py-3 text-xs sm:text-sm {$texte} shadow-xs flex items-start gap-3"]) }}>
    <div class="shrink-0 mt-0.5 {{ $couleurIcone }}" aria-hidden="true">
        @if($typeNormalise === 'success')
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
        @elseif($typeNormalise === 'warning')
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
        @elseif($typeNormalise === 'error')
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0-10.875a9 9 0 110 18 9 9 0 010-18zm0 14.25h.008v.008H12v-.008z" /></svg>
        @else
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" /></svg>
        @endif
    </div>
    <div class="flex-1 min-w-0">
        @if($titre !== null)<p class="font-bold text-sm tracking-tight mb-0.5">{{ $titre }}</p>@endif
        <div class="leading-relaxed">{{ $message ?? $slot }}</div>
    </div>
    @if($dismissible)
        <button type="button" @click="fermee = true" aria-label="Fermer cette alerte"
                class="shrink-0 -mr-1 -mt-1 p-1.5 rounded-lg opacity-60 hover:opacity-100 hover:bg-black/5 transition">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
        </button>
    @endif
</div>
