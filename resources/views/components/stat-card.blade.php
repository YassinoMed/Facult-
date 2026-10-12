{{--
    Carte statistique réutilisable (§28) : pastille + libellé + valeur,
    jamais la couleur seule. Classes Tailwind en littéraux complets
    pour la détection par le compilateur Vite.
--}}
@props(['libelle', 'valeur', 'detail' => null, 'couleur' => 'slate'])

@php
$pastilles = [
    'slate' => 'bg-slate-500',
    'emerald' => 'bg-emerald-600',
    'amber' => 'bg-amber-500',
    'rose' => 'bg-rose-600',
    'primary' => 'bg-primary-700',
];
$pastille = $pastilles[$couleur] ?? $pastilles['slate'];
@endphp

<div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
    <p class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-slate-400">
        <span class="h-2 w-2 rounded-full {{ $pastille }}" aria-hidden="true"></span>{{ $libelle }}
    </p>
    <p class="mt-1 text-3xl font-black tabular-nums text-slate-900">{{ $valeur }}</p>
    @if($detail !== null)
        <p class="text-[11px] text-slate-500">{{ $detail }}</p>
    @endif
</div>
