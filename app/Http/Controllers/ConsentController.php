<?php

namespace App\Http\Controllers;

use App\Enums\ConsentCategory;
use App\Services\Consent\ConsentPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Stores a cookie decision (accept / reject / custom / withdraw). Works for anonymous visitors, with or without JS:
 * a fetch call gets JSON with the new client configuration, a plain form post is redirected back.
 */
class ConsentController extends Controller
{
    public function store(Request $request, ConsentPolicy $policy): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:accept_all,reject_all,custom,withdraw'],
            'categories' => ['sometimes', 'array'],
            'categories.*' => ['boolean'],
        ]);

        $categories = [];
        foreach (ConsentCategory::optional() as $category) {
            $categories[$category->value] = filter_var($data['categories'][$category->value] ?? false, FILTER_VALIDATE_BOOL);
        }

        $result = $policy->record($request, $data['action'], $categories, $request->user());

        // The new decision is not in the request cookies yet: rebuild the request view of it for the answer.
        $request->cookies->set(ConsentPolicy::COOKIE, $result['cookie']->getValue());

        if ($request->expectsJson()) {
            // The page keeps its own suppression flag (admin, legal, payment forms); the answer describes the decision.
            return response()->json(['ok' => true, 'config' => $policy->clientConfig($request, suppressed: false)])->withCookie($result['cookie']);
        }

        return back()->withCookie($result['cookie']);
    }
}
