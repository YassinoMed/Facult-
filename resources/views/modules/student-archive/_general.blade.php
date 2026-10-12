<div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
    <x-stat-card libelle="Années" :valeur="$statistiques['annees']" />
    <x-stat-card libelle="Inscriptions" :valeur="$statistiques['inscriptions']" />
    <x-stat-card libelle="Documents" :valeur="$statistiques['documents']" />
    <x-stat-card libelle="Notes" :valeur="$statistiques['notes']" couleur="emerald" />
</div>

<div class="mt-4 grid gap-4 lg:grid-cols-2">
    <section aria-label="Parcours longitudinal" class="ds-card p-5">
        <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Parcours longitudinal</h2>
        @if($etudiant->inscriptionsAcademiques->isNotEmpty())
            <ul class="mt-2 space-y-1.5 text-sm">
                @foreach($etudiant->inscriptionsAcademiques->sortBy(fn ($i) => $i->anneeUniversitaire?->date_debut) as $inscription)
                    <li class="flex items-center justify-between gap-2 rounded-xl border border-slate-100 px-3.5 py-2.5">
                        <span><strong>{{ $inscription->anneeUniversitaire?->libelle ?? '—' }}</strong>
                            <span class="text-slate-500">· {{ $inscription->niveau ?? '—' }} · {{ $inscription->statut }}</span></span>
                        <x-status-badge :statut="$inscription->statut" />
                    </li>
                @endforeach
            </ul>
        @else
            <div class="mt-2"><x-empty-state message="Aucune inscription archivée." /></div>
        @endif
    </section>

    <section aria-label="Timeline récente" class="ds-card p-5">
        <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Activité récente ({{ $timeline['total'] }})</h2>
        <div class="mt-2">
            <x-timeline :elements="$timeline['elements']->take(8)->map(fn ($e) => ['titre' => $e['titre'], 'detail' => trim(($e['categorie'] ?? '') . ' ' . ($e['detail'] ?? '')), 'date' => $e['date'] instanceof \DateTimeInterface ? $e['date']->format('d/m/Y') : (string) ($e['date'] ?? '')])->all()" />
        </div>
        <a href="{{ route('admin.etudiants.archive', [$etudiant, 'onglet' => 'timeline']) }}" class="mt-2 inline-block text-xs font-bold text-primary-800 underline underline-offset-2">Toute la timeline →</a>
    </section>
</div>

@if($anomalies !== [])
    <div class="mt-4"><x-alert type="warning" titre="Points de vigilance ({{ count($anomalies) }})">
        <ul class="list-disc list-inside mt-1 text-sm">@foreach($anomalies as $anomalie)<li>{{ $anomalie['message'] ?? $anomalie }}</li>@endforeach</ul>
    </x-alert></div>
@endif

@if(isset($progression) && $progression !== null)
    <section aria-label="Progression LMD" class="mt-4 ds-card p-5">
        <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Progression LMD (dry-run)</h2>
        <p class="mt-2 text-sm">Décision : <strong>{{ $progression['decision'] ?? '—' }}</strong>
            <span class="text-slate-500">· Action : {{ $progression['action'] }}
            @if($progression['niveau_cible'] !== null)· Cible : {{ $progression['niveau_cible'] }} ({{ $progression['annee_cible'] }})@endif</span></p>
        @if($progression['blocages'] !== [])
            <p class="mt-1 text-xs text-slate-500">{{ implode(' ', $progression['blocages']) }}</p>
        @endif
    </section>
@endif
