{{-- Widget étudiant : cours du jour dans son groupe. --}}
<section aria-label="Emploi du temps" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
    <h2 class="text-base font-black text-slate-900">Emploi du temps</h2>
    @if(isset($coursAujourdhui))
        <p class="mt-2 text-3xl font-black tabular-nums text-slate-900">{{ $coursAujourdhui }}</p>
        <p class="text-xs text-slate-500">cours aujourd'hui ({{ $groupeCode }}).</p>
        @if(isset($prochainCours) && $prochainCours !== null)
            <div class="mt-3 rounded-xl border border-primary-200 bg-primary-50/60 px-4 py-3">
                <p class="text-[11px] font-bold uppercase tracking-wider text-primary-700">Prochain cours</p>
                <p class="mt-0.5 text-sm font-black text-slate-900">
                    <span class="tabular-nums">{{ $prochainCours['debut'] }} → {{ $prochainCours['fin'] }}</span>
                    · {{ $prochainCours['matiere'] }}
                </p>
                @if($prochainCours['salle'])
                    <p class="text-xs text-slate-500">Salle <span class="font-mono">{{ $prochainCours['salle'] }}</span></p>
                @endif
            </div>
        @endif
    @else
        <x-empty-state message="Aucun emploi du temps pour votre groupe." />
    @endif
    <a href="{{ route('etudiant.emploi-du-temps.semaine') }}" class="mt-3 inline-flex items-center gap-1.5 text-xs font-bold text-primary-800 underline underline-offset-2 hover:text-primary-600">
        Mon planning <span aria-hidden="true">→</span>
    </a>
</section>
