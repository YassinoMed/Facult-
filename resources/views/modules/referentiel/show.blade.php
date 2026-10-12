@extends('layouts.app')

@section('title', $modele->code . ' — ' . $def['label'])

@section('content')
    <div class="mx-auto max-w-4xl px-4 sm:px-6 py-8">
        <x-page-header :titre="$modele->code . ' — ' . $modele->nom" :description="$def['label']"
            :fil="['Référentiel' => route('admin.referentiel.tableau-bord'), $def['labels'] => route('admin.referentiel.entites.index', $entite), $modele->code => '#']">
            <x-slot:actions>
                @can('referentiel.manage')
                    <x-btn variant="secondary" href="{{ route('admin.referentiel.entites.edit', [$entite, $modele]) }}">Modifier</x-btn>
                @endcan
            </x-slot:actions>
        </x-page-header>

        @if($errors->any())
            <div class="mt-4"><x-alert type="error" titre="Opération refusée.">
                <ul class="list-disc list-inside mt-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </x-alert></div>
        @endif

        <div class="mt-4 ds-card p-6">
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                @foreach($def['champs'] as $champ)
                    @continue(in_array($champ['nom'], ['description']))
                    <div>
                        <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">{{ $champ['label'] }}</dt>
                        <dd class="mt-0.5 font-semibold text-slate-900">
                            @if($champ['type'] === 'checkbox')
                                {{ (bool) $modele->{$champ['nom']} ? '● Oui' : '○ Non' }}
                            @elseif($champ['type'] === 'select' && str_ends_with($champ['nom'], '_id'))
                                @php($relSelect = $champ['nom'] === 'type_diplome_id' ? 'typeDiplome' : str_replace('_id', '', $champ['nom']))
                                {{ $modele->$relSelect?->nom ?? '—' }}
                            @else
                                {{ $modele->{$champ['nom']} ?? '—' }}
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>
            @if($modele->description ?? null)
                <p class="mt-3 text-sm text-slate-600">{{ $modele->description }}</p>
            @endif
        </div>

        @if(!empty($def['enfants']))
            <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
                @foreach($def['enfants'] as [$relation, $libelle])
                    <div class="ds-card p-4 flex items-center justify-between gap-2">
                        <p class="text-sm font-black text-slate-900">{{ $libelle }}
                            <span class="ml-1 tabular-nums text-slate-500">{{ $modele->{$relation}()->count() }}</span></p>
                    </div>
                @endforeach
            </div>
        @endif

        @can('referentiel.manage')
            <div class="mt-4" x-data="{ confirmerSuppression: false }">
                <x-btn variant="danger" @click="confirmerSuppression = true">Supprimer</x-btn>
                <x-confirm titre="Supprimer cet élément ?" nom="confirmerSuppression" annonce="Suppression définitive, journalisée. Refusée si des données dépendantes existent.">
                    <form method="POST" action="{{ route('admin.referentiel.entites.destroy', [$entite, $modele]) }}">
                        @csrf @method('DELETE')
                        <x-btn variant="danger" type="submit">Supprimer définitivement</x-btn>
                    </form>
                </x-confirm>
            </div>
        @endcan
    </div>
@endsection
