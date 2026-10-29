{{-- Carte créneau réutilisée (grille desktop + liste mobile). --}}
@php
    $estEnCours = ($jourEstAujourdhui ?? false) && ($creneau['statut'] ?? '') !== 'cancelled' && now()->format('H:i') >= ($creneau['debut'] ?? '99:99') && now()->format('H:i') < ($creneau['fin'] ?? '00:00');
    $modificateurCreneau = $creneau['statut'] === 'cancelled'
        ? 'planning-slot--cancelled'
        : ($creneau['exception'] ? 'planning-slot--exception' : 'planning-slot--normal');
@endphp
<div class="planning-slot {{ $modificateurCreneau }} rounded-xl border px-3 py-2 text-xs
    {{ $creneau['statut'] === 'cancelled' ? 'border-rose-300 bg-rose-50' : ($creneau['exception'] ? 'border-amber-300 bg-amber-50' : 'border-slate-200 bg-slate-50') }}">
    @if($estEnCours)
        <p class="mb-1 inline-flex items-center gap-1 rounded-full bg-primary-800 px-2 py-0.5 text-[10px] font-black text-white"><span class="h-1.5 w-1.5 rounded-full bg-white animate-pulse" aria-hidden="true"></span>● EN COURS</p>
    @endif
    <p class="font-black tabular-nums text-slate-900">{{ $creneau['debut'] }}–{{ $creneau['fin'] }}</p>
    <p class="mt-0.5 font-bold text-slate-800">{{ $creneau['matiere'] }}</p>
    <p class="text-slate-500">
        {{ $typesFr[$creneau['type']] ?? $creneau['type'] }}
        @if($creneau['salle']) · <span class="font-mono">{{ $creneau['salle'] }}</span> @endif
    </p>
    @if($creneau['enseignant'])
        <p class="text-slate-500">{{ $creneau['enseignant'] }}</p>
    @endif
    @if($creneau['statut'] === 'cancelled')
        <p class="mt-1 font-black text-rose-700">❌ ANNULÉ</p>
        @if(!empty($creneau['motif']))
            <p class="text-slate-500">Motif : {{ $creneau['motif'] }}</p>
        @endif
    @elseif($creneau['exception'])
        @if(!empty($creneau['deplace']))
            <p class="mt-1 font-black text-sky-800">⇄ DÉPLACÉ</p>
        @else
            <p class="mt-1 font-black text-amber-800">⚠ MODIFIÉ</p>
        @endif
        @if(!empty($creneau['enseignant_remplace']))
            <p class="mt-0.5 font-bold text-slate-700">👤 Enseignant remplacé</p>
        @endif
        <p class="text-slate-500">Normalement : {{ $creneau['debut_normal'] }}–{{ $creneau['fin_normal'] }}@if($creneau['salle_normale']) · Salle {{ $creneau['salle_normale'] }}@endif</p>
        @if(!empty($creneau['motif']))
            <p class="text-slate-500">Motif : {{ $creneau['motif'] }}</p>
        @endif
    @endif
</div>
