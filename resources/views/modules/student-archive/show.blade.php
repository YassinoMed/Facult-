@extends('layouts.app')

@section('title', 'Archive — ' . $etudiant->nom_complet)

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header :titre="$etudiant->nom_complet" :description="'Matricule : ' . $etudiant->num_inscription . ' · ' . ($etudiant->programmeRef?->nom ?? $etudiant->niveau ?? '')"
            :fil="['Étudiants' => route('admin.etudiants.index'), $etudiant->nom_complet => route('admin.etudiants.show', $etudiant), 'Archive' => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('admin.etudiants.show', $etudiant) }}">Fiche 360</x-btn>
            </x-slot:actions>
        </x-page-header>

        <div class="mt-4 overflow-x-auto">
            <x-tabs :onglets="['general' => 'Vue générale', 'academique' => 'Académique', 'inscriptions' => 'Inscriptions', 'documents' => 'Documents', 'demandes' => 'Demandes', 'timeline' => 'Timeline', 'audit' => 'Audit'] + (auth()->user()->can('student_archive.sensitive.view') ? ['confidentiel' => 'Confidentiel'] : [])" :actif="$onglet">
                <x-slot:panneau_general>
                    @isset($statistiques)@include('modules.student-archive._general')@else<div class="p-5 text-sm text-slate-500">Consultez l'onglet Vue générale.</div>@endisset
                </x-slot:panneau_general>
                <x-slot:panneau_academique>
                    @isset($academique)@include('modules.student-archive._academique')@else<div class="p-5 text-sm text-slate-500">Onglet non autorisé.</div>@endisset
                </x-slot:panneau_academique>
                <x-slot:panneau_inscriptions>
                    @isset($parcours)@include('modules.student-archive._inscriptions')@else<div class="p-5 text-sm text-slate-500">Onglet non autorisé.</div>@endisset
                </x-slot:panneau_inscriptions>
                <x-slot:panneau_documents>
                    @isset($documents)@include('modules.student-archive._documents')@else<div class="p-5 text-sm text-slate-500">Onglet non autorisé.</div>@endisset
                </x-slot:panneau_documents>
                <x-slot:panneau_demandes>
                    @isset($demandes)@include('modules.student-archive._demandes')@else<div class="p-5 text-sm text-slate-500">Onglet non autorisé.</div>@endisset
                </x-slot:panneau_demandes>
                <x-slot:panneau_timeline>
                    @if($onglet === 'timeline' && isset($timeline) && isset($categories))
                        @include('modules.student-archive._timeline')
                    @else
                        <div class="p-5 text-sm text-slate-500">Consultez l'onglet Timeline.</div>
                    @endif
                </x-slot:panneau_timeline>
                <x-slot:panneau_audit>
                    @isset($audits)@include('modules.student-archive._audit')@else<div class="p-5 text-sm text-slate-500">Onglet non autorisé.</div>@endisset
                </x-slot:panneau_audit>
                <x-slot:panneau_confidentiel>
                    @isset($sensibles)@include('modules.student-archive._confidentiel')@else<div class="p-5 text-sm text-slate-500">Onglet non autorisé.</div>@endisset
                </x-slot:panneau_confidentiel>
            </x-tabs>
        </div>
    </div>
@endsection
