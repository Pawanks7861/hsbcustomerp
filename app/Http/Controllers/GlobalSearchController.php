<?php

namespace App\Http\Controllers;

use App\Services\GlobalSearch;
use App\Support\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class GlobalSearchController extends Controller
{
    public function __invoke(Request $request, GlobalSearch $search): JsonResponse
    {
        $term = Listing::search($request, 'q');

        try {
            $results = $search->search($request->user(), $term);
        } catch (Throwable $exception) {
            Log::warning('Global search failed', [
                'user_id' => $request->user()?->id,
                'error' => get_class($exception),
            ]);

            return response()->json(['message' => 'Search is temporarily unavailable. Please try again.'], 500);
        }

        return response()->json($results)->header('Cache-Control', 'no-store, private');
    }
}
