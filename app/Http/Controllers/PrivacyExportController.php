<?php

namespace App\Http\Controllers;

use App\Services\Privacy\AccountExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Export of the account itself as a ZIP: `ucet.json` (profile, memberships, acceptances, consent receipts,
 * requests, photo analyses, diary) plus the meal photos the person kept. Household content is the separate
 * export of the household.
 */
class PrivacyExportController extends Controller
{
    public function __invoke(Request $request, AccountExport $export): BinaryFileResponse
    {
        $path = $export->build($request->user());

        return response()->download($path, 'ucet-export-'.now()->format('Y-m-d').'.zip')->deleteFileAfterSend(true);
    }
}
