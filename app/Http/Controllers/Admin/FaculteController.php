<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\FaculteRequest;
use App\Models\Faculte;

class FaculteController extends Controller
{
    public function index()
    {
        $facultes = Faculte::withCount('etudiants')->orderBy('nom')->paginate(10);

        return view('admin.facultes.index', compact('facultes'));
    }

    public function create()
    {
        return view('admin.facultes.create');
    }

    public function store(FaculteRequest $request)
    {
        Faculte::create($request->validated());

        return redirect()->route('admin.facultes.index')
            ->with('success', 'La faculté a été créée avec succès.');
    }

    public function edit(Faculte $faculte)
    {
        return view('admin.facultes.edit', compact('faculte'));
    }

    public function update(FaculteRequest $request, Faculte $faculte)
    {
        $faculte->update($request->validated());

        return redirect()->route('admin.facultes.index')
            ->with('success', 'La faculté a été mise à jour avec succès.');
    }

    public function destroy(Faculte $faculte)
    {
        if ($faculte->etudiants()->count() > 0) {
            return redirect()->route('admin.facultes.index')
                ->with('error', 'Impossible de supprimer cette faculté car elle contient des étudiants inscrits.');
        }

        $faculte->delete();

        return redirect()->route('admin.facultes.index')
            ->with('success', 'La faculté a été supprimée avec succès.');
    }
}
