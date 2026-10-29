@extends('layouts.app')

@section('title', 'Vérification de document')

@section('content')
    <div class="mx-auto max-w-xl px-4 sm:px-6 py-8">
        <x-page-header titre="Vérification de document" description="Authenticité d’un document officiel (données minimales)." />

        <div class="mt-4 ds-card p-5" data-reveal="fade-up">
            @if(! ($trouve ?? true))
                <x-alert type="error">Code inconnu — document inexistant.</x-alert>
            @elseif(($signatureValide ?? null) === false)
                <x-alert type="error">Signature invalide — document altéré, ne pas accepter.</x-alert>
            @elseif($valide)
                <x-alert type="success">Document valide.</x-alert>
            @else
                <x-alert type="error">Document {{ $statut }} — ne pas accepter comme valide.</x-alert>
            @endif

            <dl class="mt-4 space-y-2 text-sm">
                <div class="flex justify-between"><dt class="text-slate-500">Référence</dt><dd class="font-mono font-bold">{{ $reference }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">Type</dt><dd class="font-bold">{{ $type }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">Date</dt><dd>{{ $date }}</dd></div>
                @isset($nomMasque)
                    @if($nomMasque)
                        <div class="flex justify-between"><dt class="text-slate-500">Bénéficiaire</dt><dd class="font-bold">{{ $nomMasque }}</dd></div>
                    @endif
                @endisset
                <div class="flex justify-between">
                    <dt class="text-slate-500">Signature d'intégrité</dt>
                    <dd class="font-bold">
                        @if(($signatureValide ?? null) === true)
                            Vérifiée
                        @elseif(($signatureValide ?? null) === false)
                            Invalide
                        @else
                            Non disponible (document antérieur)
                        @endif
                    </dd>
                </div>
                <div class="flex justify-between"><dt class="text-slate-500">Établissement</dt><dd>{{ $etablissement }}</dd></div>
            </dl>
        </div>
    </div>
@endsection
