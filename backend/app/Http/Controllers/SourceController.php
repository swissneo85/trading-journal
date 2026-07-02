<?php

namespace App\Http\Controllers;

use App\Models\Source;
use Illuminate\Http\Request;

class SourceController extends Controller
{
    public function index(Request $request)
    {
        $query = Source::query();

        if (! $request->boolean('all')) {
            $query->where('archived', false);
        }

        return response()->json($query->orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        return response()->json(Source::create(['name' => $validated['name']]), 201);
    }

    public function update(Request $request, Source $source)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'archived' => 'sometimes|boolean',
        ]);

        $source->update($validated);

        return response()->json($source);
    }

    public function destroy(Source $source)
    {
        $source->delete();

        return response()->json(['message' => '✅ Quelle gelöscht']);
    }
}
