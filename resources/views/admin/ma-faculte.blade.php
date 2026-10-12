@extends('layouts.app')

@section('title', 'Ma Faculté — Administration')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">

    {{-- En-tête --}}
    <div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div class="flex items-center gap-2.5">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary-100 text-primary-700">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0 0 12 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75Z" />
                </svg>
            </div>
            <div>
                <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">{{ $faculte->nom }}</h1>
                <p class="mt-0.5 text-xs sm:text-sm text-slate-500">Votre tableau de bord — périmètre strictement limité à votre faculté.</p>
            </div>
        </div>
        <span class="inline-flex items-center gap-1.5 self-start rounded-full bg-primary-50 px-3 py-1.5 text-xs font-bold text-primary-700 border border-primary-200">
            <span class="h-2 w-2 rounded-full bg-primary-500 animate-pulse"></span>
            Administration {{ $faculte->code }}
        </span>
    </div>

    {{-- Statistiques (déjà scopées serveur) --}}
    <div class="mb-8 grid grid-cols-2 lg:grid-cols-5 gap-4">
        @foreach([
            ['label' => 'Étudiants', 'valeur' => $stats['total'], 'couleur' => 'text-primary-700 bg-primary-50 border-primary-200'],
            ['label' => 'En attente', 'valeur' => $stats['en_attente'], 'couleur' => 'text-amber-700 bg-amber-50 border-amber-200'],
            ['label' => 'Validés', 'valeur' => $stats['valide'], 'couleur' => 'text-emerald-700 bg-emerald-50 border-emerald-200'],
            ['label' => 'Dossiers archives', 'valeur' => $stats['dossiers'], 'couleur' => 'text-slate-700 bg-slate-50 border-slate-200'],
            ['label' => 'Inscriptions', 'valeur' => $stats['inscriptions'], 'couleur' => 'text-accent-700 bg-accent-50 border-accent-200'],
        ] as $carte)
            <div class="rounded-2xl border {{ $carte['couleur'] }} p-4">
                <p class="text-2xl font-black">{{ $carte['valeur'] }}</p>
                <p class="mt-0.5 text-xs font-bold uppercase tracking-wider opacity-80">{{ $carte['label'] }}</p>
            </div>
        @endforeach
    </div>

    {{-- Accès rapides (tous scopés à la faculté) --}}
    <div class="mb-8 grid grid-cols-2 sm:grid-cols-4 gap-3">
        @foreach([
            ['route' => 'admin.etudiants.index', 'label' => 'Étudiants', 'icone' => 'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Z'],
            ['route' => 'admin.archives.index', 'label' => 'Archives', 'icone' => 'M3.75 9.776c.112-.017.227-.026.344-.026h15.812c.117 0 .232.009.344.026m-16.5 0a2.25 2.25 0 0 0-1.883 2.542l.857 6a2.25 2.25 0 0 0 2.227 1.932H19.05a2.25 2.25 0 0 0 2.227-1.932l.857-6a2.25 2.25 0 0 0-1.883-2.542m-16.5 0V6A2.25 2.25 0 0 1 6 3.75h3.879a1.5 1.5 0 0 1 1.06.44l2.122 2.12a1.5 1.5 0 0 0 1.06.44H18A2.25 2.25 0 0 1 20.25 9v.776'],
            ['route' => 'admin.exports.index', 'label' => 'Exports', 'icone' => 'M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3'],
            ['route' => 'admin.etudiants.index', 'label' => 'Registre', 'icone' => 'M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.967 8.967 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25'],
        ] as $lien)
            <a href="{{ route($lien['route']) }}"
               class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3.5 text-sm font-bold text-slate-700 hover:border-primary-300 hover:text-primary-700 hover:bg-primary-50/40 transition-smooth">
                <svg class="h-5 w-5 text-primary-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $lien['icone'] }}"/>
                </svg>
                {{ $lien['label'] }}
            </a>
        @endforeach
    </div>

    {{-- Derniers inscrits de SA faculté --}}
    <div class="overflow-hidden rounded-3xl border border-slate-200/90 bg-white shadow-xs">
        <div class="border-b border-slate-100 bg-slate-50/60 px-6 py-3.5">
            <h2 class="text-sm font-bold text-slate-900">Derniers inscrits de votre faculté</h2>
        </div>
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-100 text-left text-xs uppercase tracking-wider text-slate-400">
                    <th class="px-6 py-3">Étudiant</th>
                    <th class="px-4 py-3">N° Inscription</th>
                    <th class="px-4 py-3">Niveau</th>
                    <th class="px-4 py-3">Statut</th>
                    <th class="px-6 py-3 text-right">Fiche</th>
                </tr>
            </thead>
            <tbody>
                @forelse($derniers as $etudiant)
                    <tr class="border-b border-slate-50 last:border-0 hover:bg-slate-50/40 transition-smooth">
                        <td class="px-6 py-3 font-bold text-slate-900">{{ $etudiant->nom_complet }}</td>
                        <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ $etudiant->num_inscription }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded-lg bg-slate-100 px-2 py-0.5 text-xs font-bold text-slate-700">{{ $etudiant->niveau }}</span>
                        </td>
                        <td class="px-4 py-3">{!! $etudiant->statut_badge !!}</td>
                        <td class="px-6 py-3 text-right">
                            <a href="{{ route('admin.etudiants.show', $etudiant) }}"
                               class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-600 hover:bg-slate-50 transition-smooth">
                                Consulter
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-10 text-center text-sm text-slate-400">Aucun étudiant inscrit dans votre faculté.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
