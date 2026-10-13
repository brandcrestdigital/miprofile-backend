<?php
namespace App\Http\Controllers;

use App\Mail\ProfileReportMail;
use App\Models\Report;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class ReportController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'reported_user_id'     => 'required|integer|exists:users,id',
            'organization_email'   => 'required|email', // ← Added
            'reporter_name'        => 'required|string|max:255',
            'report_details'       => 'required|string',
            'reported_profile_url' => 'nullable|string',
        ]);

        $report = Report::create($validated);

        // Send Email to Admin (and optionally to organization)
        try {
            Mail::to('sk.asif0490@gmail.com')->send(new ProfileReportMail($validated));

            // Optional: Also notify organization
            if (! empty($validated['organization_email'])) {
                Mail::to($validated['organization_email'])->send(new ProfileReportMail($validated));
            }
        } catch (\Exception $e) {
            \Log::error('Report Email Failed: ' . $e->getMessage());
        }

        return response()->json([
            'success'   => true,
            'message'   => 'Report submitted successfully',
            'report_id' => $report->id,
        ], 201);
    }
}
