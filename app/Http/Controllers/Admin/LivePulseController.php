<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\LiveVersion;
use Illuminate\Http\JsonResponse;

/**
 * What the pages poll for live updates: just the data's version, cheap enough
 * to ask every few seconds. See App\LiveVersion and public/js/live.js.
 */
class LivePulseController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()
            ->json(['version' => LiveVersion::current()])
            ->header('Cache-Control', 'no-store, private');
    }
}
