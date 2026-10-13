<?php
namespace App\Http\Controllers;

use App\Models\ProfileAnalytics;
use App\Models\StaffCard;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ProfileAnalyticsController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'card_id' => 'required|integer|exists:staff_cards,id',
            'action'  => 'required|in:view,download,share,meeting',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $card = StaffCard::findOrFail($request->card_id);

        if (in_array($request->action, ['view', 'download'])) {

            $exists = ProfileAnalytics::where('card_id', $request->card_id)
                ->where('action', $request->action)
                ->where('ip_address', $request->ip())
                ->whereDate('created_at', Carbon::today())
                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => true,
                    'message' => ucfirst($request->action) . ' already counted.',
                ]);
            }
        }

        ProfileAnalytics::create([
            'card_id'    => $card->id,
            'user_id'    => $card->user_id,
            'action'     => $request->action,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => ucfirst($request->action) . ' recorded successfully.',
        ]);
    }

    public function profilePerformance()
    {
        $organizationId = auth()->user()->organization_id;

        $teamMembers = StaffCard::where('organization_id', $organizationId)
            ->select('id', 'name', 'designation', 'profile_image')
            ->get()
            ->map(function ($card) {

                $views = ProfileAnalytics::where('card_id', $card->id)
                    ->where('action', 'view')
                    ->count();

                $downloads = ProfileAnalytics::where('card_id', $card->id)
                    ->where('action', 'download')
                    ->count();

                $shares = ProfileAnalytics::where('card_id', $card->id)
                    ->where('action', 'share')
                    ->count();

                $meetings = ProfileAnalytics::where('card_id', $card->id)
                    ->where('action', 'meeting')
                    ->count();

                return [
                    'id'          => $card->id,
                    'name'        => $card->name,
                    'designation' => $card->designation,
                    'image'       => $card->profile_image
                        ? asset('storage/' . $card->profile_image)
                        : null,

                    'views'       => $views,
                    'downloads'   => $downloads,
                    'shares'      => $shares,
                    'meetings'    => $meetings,

                    // Total engagement
                    'total'       => $views + $downloads + $shares + $meetings,
                ];
            })
            ->sortByDesc('total') // Sort by total engagement
            ->take(5)             // Top 5 only
            ->values();           // Reset array keys

        return response()->json([
            'success' => true,
            'data'    => $teamMembers,
        ]);
    }

    public function performanceChart($cardId)
    {
        $chart = ProfileAnalytics::select(
            DB::raw('DATE(created_at) as day'),
            DB::raw("SUM(CASE WHEN action='view' THEN 1 ELSE 0 END) as views"),
            DB::raw("SUM(CASE WHEN action='download' THEN 1 ELSE 0 END) as downloads"),
            DB::raw("SUM(CASE WHEN action='share' THEN 1 ELSE 0 END) as shares"),
            DB::raw("SUM(CASE WHEN action='meeting' THEN 1 ELSE 0 END) as meetings")
        )
            ->where('card_id', $cardId)
            ->whereDate('created_at', '>=', now()->subDays(6))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('day')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $chart,
        ]);
    }

    public function recentActivity()
    {
        $organizationId = auth()->user()->organization_id;

        $activities = ProfileAnalytics::with('card')
            ->whereHas('card', function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            })
            ->latest()
            ->take(7) // Top 7 latest activities
            ->get()
            ->map(function ($item) {

                $icon  = "👁️";
                $color = "bg-orange-100";
                $title = "Profile Viewed";

                switch ($item->action) {
                    case 'download':
                        $icon  = "📥";
                        $color = "bg-green-100";
                        $title = "Contact Downloaded";
                        break;

                    case 'share':
                        $icon  = "🔗";
                        $color = "bg-blue-100";
                        $title = "Profile Shared";
                        break;

                    case 'meeting':
                        $icon  = "📅";
                        $color = "bg-purple-100";
                        $title = "Meeting Booked";
                        break;
                }

                return [
                    'id'    => $item->id,
                    'icon'  => $icon,
                    'color' => $color,
                    'title' => $title,
                    'user'  => optional($item->card)->name,
                    'time'  => $item->created_at->diffForHumans(),
                ];
            });

        return response()->json([
            'success' => true,
            'data'    => $activities,
        ]);
    }

}