<?php

namespace App\Http\Controllers;

use App\Services\ExportService;
use App\Support\CurrentHousehold;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportController extends Controller
{
    public function __invoke(Request $request, CurrentHousehold $current, ExportService $export): BinaryFileResponse
    {
        $household = $current->get();
        $request->user()->can('view', $household) || abort(403);

        $path = $export->build($household);

        return response()->download($path, 'recepty-export-'.now()->format('Y-m-d').'.zip')->deleteFileAfterSend(true);
    }
}
