<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MenuTutorialController extends Controller
{
    public function complete(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user && $user->menu_tutorial_completed_at === null) {
            $user->forceFill([
                'menu_tutorial_completed_at' => now(),
            ])->save();
        }

        return back();
    }
}
