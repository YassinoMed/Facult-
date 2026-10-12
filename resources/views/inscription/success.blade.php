@extends('layouts.app')

@section('title', 'Attestation d\'Inscription — Confirmation')
@section('meta_description', 'Votre demande d\'inscription est validée avec succès. Téléchargez ou imprimez votre récépissé officiel.')

@section('content')
<div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 py-10 sm:py-16">

    {{-- Main Container Card --}}
    <div class="rounded-3xl border border-slate-200/90 bg-white shadow-2xl shadow-primary-950/10 backdrop-blur-xl overflow-hidden print-card"
         x-data="{ copied: false }"
         x-init="try { localStorage.removeItem('inscription-brouillon-v1'); } catch(e) {}">

        {{-- Top Success Status Banner --}}
        <div class="bg-gradient-to-r from-emerald-600 via-teal-600 to-emerald-700 px-6 py-8 sm:px-10 text-white text-center sm:text-left flex flex-col sm:flex-row sm:items-center justify-between gap-6">
            <div class="flex flex-col sm:flex-row items-center gap-4">
                <div class="flex h-16 w-16 items-center justify-center rounded-2xl bg-white/20 backdrop-blur-md shadow-md">
                    <svg class="h-9 w-9 text-white" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                </div>
                <div>
                    <span class="inline-block rounded-full bg-white/20 px-3 py-0.5 text-[10px] font-bold uppercase tracking-wider text-white mb-1">
                        Dossier Transmis avec Succès
                    </span>
                    <h1 class="text-2xl sm:text-3xl font-black tracking-tight">Inscription enregistrée</h1>
                    <p class="text-xs sm:text-sm text-emerald-100">Votre demande a été enregistrée avec succès. Votre dossier sera traité par l'administration.</p>
                </div>
            </div>

            <div class="no-print">
                <button type="button"
                        onclick="window.print()"
                        class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl bg-white px-4 py-2.5 text-xs font-bold text-emerald-800 shadow-md hover:bg-emerald-50 transition-smooth">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24-1.076-.673-2.13-1.28-3.14m0 0A9.953 9.953 0 0 1 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-1.474 0-2.887-.319-4.158-.89m0 0L3 21l1.89-3.72" />
                    </svg>
                    Imprimer / PDF
                </button>
            </div>
        </div>

        {{-- Official Certificate Ticket Body --}}
        <div class="p-6 sm:p-10 space-y-8">

            {{-- Numéro d'inscription Display Card --}}
            <div class="rounded-2xl border-2 border-dashed border-primary-200 bg-primary-50/50 p-6 sm:p-8 text-center relative">
                <p class="text-xs font-bold uppercase tracking-widest text-primary-700">Identifiant Unique d'Admission</p>
                <div class="mt-3 flex flex-wrap items-center justify-center gap-3">
                    <span class="font-mono text-3xl sm:text-4xl font-black tracking-widest text-slate-900 bg-white px-5 py-2.5 rounded-xl border border-primary-100 shadow-sm">
                        {{ session('num_inscription', 'INS-' . date('Y') . '-XXXXX') }}
                    </span>

                    {{-- Copy button --}}
                    <button type="button"
                            @click="navigator.clipboard.writeText('{{ session('num_inscription', '') }}'); copied = true; setTimeout(() => copied = false, 2500)"
                            class="no-print inline-flex items-center gap-1.5 rounded-xl border border-primary-200 bg-white px-3.5 py-3 text-xs font-bold text-primary-700 shadow-xs hover:bg-primary-50 transition-smooth">
                        <template x-if="!copied">
                            <span class="flex items-center gap-1.5">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0 0 13.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 0 1-.75.75H9a.75.75 0 0 1-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 0 1-2.25 2.25H6.75A2.25 2.25 0 0 1 4.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 0 1 1.927-.184" />
                                </svg>
                                Copier
                            </span>
                        </template>
                        <template x-if="copied">
                            <span class="flex items-center gap-1.5 text-emerald-600 font-bold">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                </svg>
                                Numéro copié !
                            </span>
                        </template>
                    </button>
                </div>
                <p class="mt-3 text-xs text-slate-500">
                    Ce numéro officiel est indispensable pour le suivi de votre dossier et le retrait de votre carte d'étudiant.
                </p>
            </div>

            {{-- Recap Details Table --}}
            <div class="rounded-2xl border border-slate-200 bg-slate-50/50 p-6">
                <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-4">Récapitulatif de la candidature</h3>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 text-xs sm:text-sm">
                    <div>
                        <span class="block text-slate-400 text-xs">Candidat</span>
                        <span class="block font-bold text-slate-900 mt-0.5 text-base">
                            {{ session('nom_etudiant', 'Étudiant Inscrit') }}
                        </span>
                    </div>

                    <div>
                        <span class="block text-slate-400 text-xs">Faculté d'affectation</span>
                        <span class="block font-bold text-slate-900 mt-0.5">
                            {{ session('faculte_nom', 'Faculté Universitaire') }}
                        </span>
                    </div>

                    <div>
                        <span class="block text-slate-400 text-xs">Statut</span>
                        <span class="mt-0.5 inline-flex items-center gap-1.5 rounded-full border border-amber-300 bg-amber-50 px-2.5 py-1 text-xs font-bold text-amber-900">
                            <span class="h-1.5 w-1.5 rounded-full bg-amber-500" aria-hidden="true"></span>
                            En attente
                        </span>
                    </div>

                    <div>
                        <span class="block text-slate-400 text-xs">Date d'enregistrement</span>
                        <span class="block font-bold text-slate-900 mt-0.5">
                            {{ date('d/m/Y') }}
                        </span>
                    </div>
                </div>
            </div>

            {{-- Identifiels Espace Étudiant --}}
            <div class="flex items-start gap-3 rounded-2xl bg-primary-50/80 border border-primary-200/80 p-4 sm:p-5">
                <div class="flex-shrink-0 mt-0.5 text-primary-600">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.263-1.343.818l-.5 1.402m-3.428-7.132A6 6 0 0 1 3.75 8.25a6 6 0 0 1 5.879-6 6 6 0 0 1 5.871 6c0 .318-.024.63-.07.937-.107.684-.81 1.09-1.497.882l-1.183-.357c-.5-.152-.985.211-1.185.683a2.25 2.25 0 0 1-1.544 1.344c-.243.061-.49.089-.738.089-.89 0-1.724-.27-2.437-.77a6.042 6.042 0 0 1-1.394-1.302 2.23 2.23 0 0 1 .666-3.383c.192-.12.382-.232.57-.347M6.75 18v3.75m4.5-1.5v2.25m4.5-4.5v4.5" />
                    </svg>
                </div>
                <div class="text-xs sm:text-sm">
                    <p class="font-bold text-primary-900">Identifiants de votre Espace Étudiant enregistrés</p>
                    <p class="mt-0.5 text-primary-900/70 leading-relaxed">
                        Votre <span class="font-semibold">email</span> (ou CIN) et le <span class="font-semibold">mot de passe</span> que vous venez de créer vous donnent accès
                        à votre espace personnel de suivi de dossier. Conservez-les précieusement : ils ne seront jamais communiqués par téléphone ou email.
                    </p>
                    <a href="{{ route('etudiant.connexion.show') }}"
                       class="mt-3 inline-flex items-center gap-2 rounded-xl bg-primary-600 px-4 py-2.5 text-xs font-bold text-white shadow-md shadow-primary-500/25 hover:bg-primary-700 hover:shadow-lg transition-smooth">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.263-1.343.818l-.5 1.402m-3.428-7.132A6 6 0 0 1 3.75 8.25a6 6 0 0 1 5.879-6 6 6 0 0 1 5.871 6c0 .318-.024.63-.07.937-.107.684-.81 1.09-1.497.882l-1.183-.357c-.5-.152-.985.211-1.185.683a2.25 2.25 0 0 1-1.544 1.344c-.243.061-.49.089-.738.089-.89 0-1.724-.27-2.437-.77a6.042 6.042 0 0 1-1.394-1.302 2.23 2.23 0 0 1 .666-3.383c.192-.12.382-.232.57-.347" />
                        </svg>
                        Accéder à mon Espace Étudiant
                    </a>
                </div>
            </div>

            {{-- Statut & Délais --}}
            <div class="flex items-start gap-3 rounded-2xl bg-amber-50/80 border border-amber-200/80 p-4 sm:p-5 text-amber-900">
                <div class="flex-shrink-0 mt-0.5 text-amber-600">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                </div>
                <div class="text-xs sm:text-sm">
                    <p class="font-bold">Statut actuel : En attente de validation académique</p>
                    <p class="mt-0.5 text-amber-800 leading-relaxed">
                        Le service de la scolarité de la faculté examine actuellement les pièces fournies. Vous recevrez une notification dans votre espace étudiant dès que le statut aura évolué.
                    </p>
                </div>
            </div>

            {{-- Action buttons (§11) : récépissé, impression, accueil, espace étudiant --}}
            <div class="no-print pt-4 flex flex-col sm:flex-row items-center justify-between gap-4 border-t border-slate-100">
                <div class="flex flex-col sm:flex-row gap-3 w-full sm:w-auto">
                    <button type="button"
                            onclick="window.print()"
                            class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-5 py-3 text-xs sm:text-sm font-semibold text-slate-700 hover:bg-slate-50 transition-smooth">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24-1.076-.673-2.13-1.28-3.14m0 0A9.953 9.953 0 0 1 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-1.474 0-2.887-.319-4.158-.89m0 0L3 21l1.89-3.72" />
                        </svg>
                        Télécharger le récépissé / Imprimer
                    </button>

                    <a href="{{ route('inscription.create') }}"
                       class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-5 py-3 text-xs sm:text-sm font-semibold text-slate-700 hover:bg-slate-50 transition-smooth">
                        Retour à l'accueil
                    </a>
                </div>

                <a href="{{ route('etudiant.connexion.show') }}"
                   class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl bg-primary-800 px-5 py-3 text-xs sm:text-sm font-bold text-white hover:bg-primary-700 transition-smooth">
                    Accéder à mon espace étudiant
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                    </svg>
                </a>
            </div>
        </div>
    </div>
</div>
@endsection
