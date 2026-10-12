@extends('layouts.app')

@section('title', 'Inscription pédagogique')

@section('content')
    <div class="mx-auto max-w-4xl px-4 sm:px-6 py-8">
        <x-page-header :titre="'Pédagogique — ' . $inscription->etudiant->nom_complet" :description="$inscription->anneeUniversitaire->libelle . ' · ' . ($inscription->programmeRef?->code ?? $inscription->niveau)"
            :fil="['Registre' => route('admin.etudiants.index'), 'Inscription' => '#']" />

        @if($errors->any())
            <div class="mt-4"><x-alert type="error" titre="Inscription refusée.">
                <ul class="list-disc list-inside mt-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </x-alert></div>
        @endif

        <section aria-label="ECUE inscrites" class="mt-4 ds-card p-5">
            <h2 class="text-base font-black text-slate-900">ECUE inscrites ({{ $inscription->pedagogiques->count() }})</h2>
            @if($inscription->pedagogiques->isNotEmpty())
                <ul class="mt-3 space-y-1.5 text-sm">
                    @foreach($inscription->pedagogiques as $pedago)
                        <li class="flex items-center justify-between gap-2 rounded-xl border border-slate-100 px-3.5 py-2.5">
                            <span><strong class="font-mono">{{ $pedago->matiere->code }}</strong> — {{ $pedago->matiere->nom }}
                                <span class="text-slate-500">· {{ $pedago->ue->code }} · {{ $pedago->statut }}</span></span>
                            @if($pedago->statut === 'inscrit')
                                <form method="POST" action="{{ route('admin.inscriptions.ecues.destroy', $pedago) }}" class="inline">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="text-xs font-bold text-rose-700 underline underline-offset-2 min-h-[32px]">Retirer</button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @else
                <div class="mt-3"><x-empty-state message="Aucune ECUE inscrite." /></div>
            @endif
        </section>

        @if($maquette)
            <section aria-label="Maquette active" class="mt-4 ds-card p-5">
                <h2 class="text-base font-black text-slate-900">Maquette active v{{ $maquette->version }} — inscrivable</h2>
                <ul class="mt-3 space-y-1.5 text-sm">
                    @foreach($maquette->unitesEnseignement as $ue)
                        @foreach($ue->ecues as $ecue)
                            @php($deja = $inscription->pedagogiques->firstWhere('matiere_id', $ecue->id))
                            <li class="flex items-center justify-between gap-2 rounded-xl border border-slate-100 px-3.5 py-2.5">
                                <span><strong class="font-mono">{{ $ecue->code }}</strong> — {{ $ecue->nom }}
                                    <span class="text-slate-500">· {{ $ue->code }}</span></span>
                                @if($deja)
                                    <span class="text-xs font-bold text-emerald-700">✓ {{ $deja->statut }}</span>
                                @else
                                    <form method="POST" action="{{ route('admin.inscriptions.ecues.store', $inscription) }}" class="inline">
                                        @csrf
                                        <input type="hidden" name="matiere_id" value="{{ $ecue->id }}">
                                        <button type="submit" class="text-xs font-bold text-primary-800 underline underline-offset-2 min-h-[32px]">Inscrire</button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    @endforeach
                </ul>
            </section>
        @else
            <div class="mt-4"><x-alert type="warning" titre="Aucune maquette active.">L'inscription pédagogique exige une maquette active pour ce programme et cette année.</x-alert></div>
        @endif
    </div>
@endsection
