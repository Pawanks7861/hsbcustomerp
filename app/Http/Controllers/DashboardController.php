<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Support\BoqTotals;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $projects = Project::query()
            ->visibleTo($request->user())
            ->with(['boqs.currentItems.budgets', 'boqs.currentItems.children'])
            ->orderBy('project_name')
            ->get()
            ->map(fn (Project $project) => BoqTotals::projectPayload($project));

        return Inertia::render('Dashboard', [
            'projects' => $projects,
        ]);
    }
}
