<?php
namespace App\Http\Controllers;

use App\Models\DigitalCard;
use App\Models\Organization;
use App\Models\OrganizationCardPurchase;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {

     
        $from = $request->get('from');
        $to   = $request->get('to');

        $fromDate = $from ? Carbon::parse($from)->startOfDay() : null;
        $toDate   = $to ? Carbon::parse($to)->endOfDay() : null;

        /* ─────────────────────────────
       FILTER BASE QUERY
    ───────────────────────────── */

        $purchaseQuery = OrganizationCardPurchase::query();

        if ($fromDate) {
            $purchaseQuery->where('created_at', '>=', $fromDate);
        }

        if ($toDate) {
            $purchaseQuery->where('created_at', '<=', $toDate);
        }

        /* ─────────────────────────────
       KPI CARDS
    ───────────────────────────── */

        $totalOrganizations = Organization::count();

        $activeCards = DigitalCard::where('status', 'active')->count();

        $inactiveCards = DigitalCard::where('status', 'inactive')->count();

        $totalRevenue = (clone $purchaseQuery)->sum('total_amount');

        /* ─────────────────────────────
       MONTHLY CHART DATA
    ───────────────────────────── */

        $monthlyData = (clone $purchaseQuery)
            ->select(
                DB::raw('MONTH(created_at) as month'),
                DB::raw('SUM(total_cards) as cards'),
                DB::raw('SUM(total_amount) as revenue')
            )
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $months = [
            1 => 'Jan', 2  => 'Feb', 3  => 'Mar', 4  => 'Apr',
            5 => 'May', 6  => 'Jun', 7  => 'Jul', 8  => 'Aug',
            9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec',
        ];

        $chartData = [];

        foreach ($monthlyData as $data) {
            $chartData[] = [
                'month'   => $months[$data->month],
                'users'   => (int) $data->cards, // keep as "users" because chart UI uses that key
                'revenue' => (float) $data->revenue,
            ];
        }

        /* ─────────────────────────────
       FINAL RESPONSE
    ───────────────────────────── */

        return response()->json([
            'success' => true,
            'data'    => [
                'stats'     => [
                    'organizations'  => $totalOrganizations,
                    'active_cards'   => $activeCards,
                    'inactive_cards' => $inactiveCards,
                    'revenue'        => (float) $totalRevenue,
                ],
                'chartData' => $chartData,
            ],
        ]);
    }

}