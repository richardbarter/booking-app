<?php

namespace App\Http\Controllers\Playground;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Services\CurrentBusiness;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The playground's diary page for the visitor's sandbox, which UseSandbox
 * has made the current business (08). It never reads a business from the
 * request.
 */
class DiaryController extends Controller
{
    public function show(CurrentBusiness $currentBusiness): Response
    {
        $business = $currentBusiness->get();

        return Inertia::render('playground/Show', [
            'business' => [
                'name' => $business->name,
                'timezone' => $business->timezone,
                'strictness' => $business->strictness,
                'relaxationHours' => $business->relaxation_hours,
                'minNoticeMinutes' => $business->min_notice_minutes,
            ],
            'services' => Service::query()
                ->active()
                ->orderBy('id')
                ->get(['id', 'name', 'duration_minutes'])
                ->map(fn (Service $service): array => [
                    'id' => $service->id,
                    'name' => $service->name,
                    'durationMinutes' => $service->duration_minutes,
                ])
                ->all(),
        ]);
    }
}
