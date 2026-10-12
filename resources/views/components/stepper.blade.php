{{--
    Stepper de processus (§14) : pastille + libellé + état textuel.
    $etapes = [['Fichier', true], ...] ou [['label' => .., 'fait' => ..]].
    $courant = index 1-based de l'étape en cours.
--}}
@props(['etapes' => [], 'courant' => 1])

@php
$normalisees = collect($etapes)->map(fn ($e) => is_array($e) && array_key_exists('label', $e) ? $e : ['label' => is_array($e) ? ($e[0] ?? '') : $e, 'fait' => is_array($e) ? ($e[1] ?? false) : false])->values();
@endphp

<nav aria-label="Progression">
    <ol class="flex flex-col sm:flex-row sm:items-center gap-2">
        @foreach($normalisees as $etape)
            @php $numero = $loop->iteration; $etat = $numero < $courant || $etape['fait'] ? 'fait' : ($numero === $courant ? 'cours' : 'attente'); @endphp
            <li class="flex items-center gap-2 flex-1 rounded-xl border px-3 py-2 text-xs font-bold
                {{ $etat === 'cours' ? 'border-primary-700 bg-primary-50 text-primary-900' : ($etat === 'fait' ? 'border-emerald-300 bg-emerald-50 text-emerald-900' : 'border-slate-200 bg-white text-slate-400') }}">
                <span class="ds-step-dot {{ $etat === 'cours' ? 'bg-primary-800 text-white' : ($etat === 'fait' ? 'bg-emerald-600 text-white' : 'bg-slate-200 text-slate-500') }}" aria-hidden="true">
                    @if($etat === 'fait') ✓ @else {{ $numero }} @endif
                </span>
                <span>{{ $etape['label'] }}
                    <span class="sr-only">{{ $etat === 'cours' ? '(étape en cours)' : ($etat === 'fait' ? '(terminée)' : '(à venir)') }}</span>
                </span>
            </li>
            @if(!$loop->last)
                <span class="hidden sm:block h-px w-4 bg-slate-300" aria-hidden="true"></span>
            @endif
        @endforeach
    </ol>
</nav>
