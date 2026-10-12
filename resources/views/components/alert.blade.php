{{--
    Alerte sémantique (§19/§27) : icône + texte, jamais la couleur seule.
    Types : success | warning | error | info.
--}}
@props(['type' => 'info', 'titre' => null, 'message' => null])

@php
$styles = [
    'success' => ['border-emerald-300', 'bg-emerald-50', 'text-emerald-900', '✓'],
    'warning' => ['border-amber-300', 'bg-amber-50', 'text-amber-900', '⚠'],
    'error' => ['border-rose-300', 'bg-rose-50', 'text-rose-900', '✕'],
    'info' => ['border-sky-300', 'bg-sky-50', 'text-sky-900', 'ℹ'],
];
[$bordure, $fond, $texte, $icone] = $styles[$type] ?? $styles['info'];
@endphp

<div role="{{ $type === 'error' ? 'alert' : 'status' }}"
     class="rounded-xl border {{ $bordure }} {{ $fond }} px-4 py-3 text-xs sm:text-sm {{ $texte }}">
    <p class="flex items-start gap-2">
        <span aria-hidden="true" class="font-black">{{ $icone }}</span>
        <span>
            @if($titre !== null)<strong>{{ $titre }}</strong><br>@endif
            {{ $message ?? $slot }}
        </span>
    </p>
</div>
