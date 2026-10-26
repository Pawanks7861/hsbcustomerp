<?php

namespace App\Http\Controllers;

use App\Services\MaterialUnitService;
use App\Support\MaterialUnits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaterialUnitController extends Controller
{
    public function store(Request $request, MaterialUnitService $units): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50'],
        ]);

        $unit = $units->create($validated['name'], $request->user()?->id);

        return response()->json([
            'name' => $unit->name,
            'units' => MaterialUnits::names(),
        ]);
    }
}
