@extends('layouts.app')

@section('title', 'Demande de mise à jour enregistrée')
@section('meta_description', 'Confirmation de la prise en compte de votre demande de mise à jour de dossier.')

@section('content')
<div class="mx-auto max-w-2xl px-4 sm:px-6 py-10 sm:py-16 space-y-6 text-center">

    <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-emerald-100">
        <svg class="h-8 w-8 text-emerald-700" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
        </svg>
    </div>

    <h1 class="text-2xl sm:text-3xl font-black tracking-tight text-slate-900">
        Votre demande a été enregistrée
    </h1>

    @if ($numInscription !== '')
        <p class="text-sm text-slate-600">
            Référence de votre dossier :
            <span class="rounded bg-slate-100 px-2 py-0.5 font-mono font-bold text-slate-900">{{ $numInscription }}</span>
        </p>
    @endif

    <div class="rounded-3xl border border-slate-200/90 bg-white p-6 sm:p-8 text-left shadow-sm space-y-3 text-sm text-slate-700">
        <p>
            @if ($nbChampsModifies > 0)
                Vos modifications ({{ $nbChampsModifies }} champ{{ $nbChampsModifies > 1 ? 's' : '' }}) ont été
                enregistrées et conservent l'historique de votre dossier.
            @else
                Votre demande a été enregistrée. Aucune donnée n'a été modifiée.
            @endif
        </p>
        <p>Votre dossier reste <strong>en attente de traitement administratif</strong> : le service de la scolarité
            vérifiera vos informations avant toute décision. Vous serez informé(e) de la suite donnée.</p>

        {{-- Distinction explicite « demande enregistrée » ≠ « email envoyé ».
             Une demande sans changement réel n'engendre AUCUN email. --}}
        @if ($nbChampsModifies === 0)
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-slate-700" role="status">
                Aucune donnée n'a été modifiée : aucun email de confirmation n'était nécessaire.
                Votre dossier reste inchangé.
            </div>
        @elseif ($emailEnAttente)
            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-amber-900" role="status">
                L'envoi de l'email de confirmation a <strong>échoué</strong>.
                Votre demande est bien enregistrée : seule la notification diffère. En cas de doute,
                contactez la scolarité avec votre référence de dossier.
            </div>
        @else
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-900" role="status">
                Un email de confirmation vous a été adressé à l'adresse enregistrée sur votre dossier.
            </div>
        @endif

        <p class="text-xs text-slate-500">
            Pour toute question, contactez le service de la scolarité :
            <strong>{{ $contactScolarite['email'] ?? '' }}</strong>
            @if (! empty($contactScolarite['horaires']))
                · {{ $contactScolarite['horaires'] }}
            @endif
        </p>
    </div>

    <div class="flex flex-col sm:flex-row justify-center gap-3">
        <a href="{{ route('inscription.suivi') }}"
           class="inline-flex items-center justify-center rounded-xl bg-primary-800 px-6 py-3 text-sm font-bold text-white hover:bg-primary-700 transition min-h-[48px]">
            Suivre mon dossier
        </a>
        <a href="{{ route('inscription.existant.accueil') }}"
           class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-6 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 transition min-h-[48px]">
            Retour à l'accueil du parcours
        </a>
    </div>
</div>
@endsection
