@extends('layouts.app')

@section('title', 'Vue hiérarchique — Référentiel LMD')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Arbre du référentiel" description="Établissement → maquettes : expand/collapse, compteurs, accès direct."
            :fil="['Référentiel' => route('admin.referentiel.tableau-bord'), 'Arbre' => '#']" />

        <form method="GET" action="{{ route('admin.referentiel.arbre') }}" role="search" class="mt-4 flex flex-col sm:flex-row gap-2">
            <input type="search" name="q" value="{{ $recherche }}" placeholder="Filtrer par code ou nom…"
                   aria-label="Filtrer l'arbre" class="flex-1 rounded-xl border border-slate-300 px-4 py-2.5 text-sm min-h-[44px]">
            <x-btn type="submit" variant="secondary">Filtrer</x-btn>
        </form>

        @php($motif = mb_strtolower($recherche))
        @php($filtre = fn ($code, $nom) => $motif === '' || str_contains(mb_strtolower($code . ' ' . $nom), $motif))
        <div class="mt-4 space-y-3">
            @foreach($facultes as $faculte)
                <details class="ds-card p-4" open>
                    <summary class="cursor-pointer text-sm font-black text-slate-900 min-h-[44px] flex items-center gap-2">
                        🏛 {{ $faculte->nom }}
                        <span class="text-[11px] font-bold text-slate-500">{{ $faculte->departements->count() }} dép. · {{ $faculte->domaines->count() }} dom.</span>
                    </summary>
                    @foreach($faculte->domaines->filter(fn ($d) => $filtre($d->code, $d->nom)) as $domaine)
                        <details class="mt-2 ml-2 sm:ml-4 rounded-xl border border-slate-200 p-3">
                            <summary class="cursor-pointer text-xs font-black text-slate-800 min-h-[36px] flex items-center gap-2">
                                📚 {{ $domaine->code }} — {{ $domaine->nom }}
                                <span class="text-slate-500">({{ $domaine->mentions->count() }})</span>
                            </summary>
                            @foreach($domaine->mentions->filter(fn ($m) => $filtre($m->code, $m->nom)) as $mention)
                                <details class="mt-2 ml-2 sm:ml-4 rounded-xl border border-slate-100 bg-slate-50/60 p-3">
                                    <summary class="cursor-pointer text-xs font-bold text-slate-700 min-h-[36px] flex items-center gap-2">
                                        🎓 {{ $mention->code }} — {{ $mention->nom }}
                                        <span class="text-slate-500">({{ $mention->specialites->count() }})</span>
                                    </summary>
                                    @foreach($mention->specialites->filter(fn ($s) => $filtre($s->code, $s->nom)) as $specialite)
                                        <div class="mt-2 ml-2 sm:ml-4 text-xs">
                                            <p class="font-black text-slate-800">🧭 {{ $specialite->code }} — {{ $specialite->nom }}</p>
                                            @foreach($specialite->programmes as $programme)
                                                @php($maquetteActive = $programme->maquettes->first())
                                                @php($uesProgramme = $programme->niveaux->flatMap(fn ($n) => $n->semestres->flatMap->unitesEnseignement)->unique('id')->values())
                                                <details class="mt-1.5 rounded-xl border border-slate-200 bg-white p-3">
                                                    <summary class="cursor-pointer font-bold min-h-[36px] flex flex-wrap items-center gap-2">
                                                        <a href="{{ route('admin.referentiel.entites.show', ['programmes', $programme]) }}" class="text-primary-800 underline underline-offset-2">{{ $programme->code }} — {{ $programme->nom }}</a>
                                                        <span class="text-slate-500">({{ $programme->niveaux->count() }} niveaux · {{ $uesProgramme->count() }} UE · {{ $uesProgramme->flatMap->ecues->unique('id')->count() }} ECUE)</span>
                                                        @if($maquetteActive)
                                                            <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-black text-emerald-800">Maquette active v{{ $maquetteActive->version }}</span>
                                                        @else
                                                            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-black text-amber-800">Sans maquette active</span>
                                                        @endif
                                                    </summary>
                                                    <p class="mt-1 text-[11px] text-slate-500">Chemin : {{ $faculte->nom }} › {{ $domaine->code }} › {{ $mention->code }} › {{ $specialite->code }} › {{ $programme->code }}</p>
                                                    @foreach($programme->niveaux as $niveau)
                                                        <p class="mt-1.5 font-bold text-slate-700">▪ {{ $niveau->code }}</p>
                                                        @foreach($niveau->semestres as $semestre)
                                                            <p class="ml-3 mt-0.5 text-slate-600">▸ {{ $semestre->code }}
                                                                @foreach($semestre->unitesEnseignement as $ue)
                                                                    <a href="{{ route('admin.referentiel.entites.show', ['ues', $ue]) }}" class="font-bold text-primary-800 underline underline-offset-2">{{ $ue->code }}</a><span class="text-slate-400">({{ $ue->ecues->count() }})</span>{{ !$loop->last ? ' · ' : '' }}
                                                                @endforeach
                                                                @if($semestre->unitesEnseignement->isEmpty())
                                                                    <span class="text-amber-600">sans UE</span>
                                                                @endif
                                                            </p>
                                                        @endforeach
                                                    @endforeach
                                                    <p class="mt-1.5">
                                                        <a href="{{ route('admin.referentiel.prevalidation.show', $programme) }}" class="text-[11px] font-bold text-primary-800 underline underline-offset-2">Prévalidation →</a>
                                                    </p>
                                                </details>
                                            @endforeach
                                        </div>
                                    @endforeach
                                </details>
                            @endforeach
                        </details>
                    @endforeach
                    @if($faculte->departements->isNotEmpty())
                        <p class="mt-2 ml-2 text-[11px] text-slate-500">Départements :
                            @foreach($faculte->departements as $departement)
                                <a href="{{ route('admin.referentiel.entites.show', ['departements', $departement]) }}" class="font-bold text-primary-800 underline underline-offset-2">{{ $departement->code }}</a>{{ !$loop->last ? ' · ' : '' }}
                            @endforeach
                        </p>
                    @endif
                </details>
            @endforeach
        </div>
    </div>
@endsection
