<?php
namespace App\Http\Controllers;

use App\Models\DigitalCard;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\OrganizationCardPurchase;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10); // Default 10 records per page

        $employees = Employee::
            orderBy('id', 'desc')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Staff list fetched successfully.',
            'data'    => $employees,
        ]);
    }

    /**
     * Update the specified employee in storage.
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:255',
            'email'       => 'required|email|max:255|unique:employees,email,' . $id,
            'phone'       => 'nullable|string|min:10|max:13|unique:employees,phone,' . $id,
            'designation' => 'nullable|string|max:100',
            'department'  => 'nullable|string|max:100',
            'is_active'   => 'boolean',
            'password'    => 'nullable|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation Error',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $employee = Employee::findOrFail($id);

        $oldEmail = $employee->email;
        $oldPhone = $employee->phone;

        $changes = [];

        // Update employee details
        $employee->update([
            'name'        => $request->name,
            'email'       => $request->email,
            'phone'       => $request->phone,
            'designation' => $request->designation,
            'department'  => $request->department,
            'is_active'   => $request->boolean('is_active', true),
        ]);

        // Detect changes
        if ($oldEmail !== $request->email) {
            $changes[] = 'Email Address';
        }
        if ($oldPhone !== $request->phone) {
            $changes[] = 'Phone Number';
        }
        if ($request->filled('password')) {
            $employee->update(['password' => Hash::make($request->password)]);
            $changes[] = 'Password';
        }

        // Send email notification if any changes were made
        if (! empty($changes)) {
            try {
                $details = [
                    'name'     => $employee->name,
                    'email'    => $employee->email,
                    'changes'  => $changes,
                    'password' => $request->filled('password') ? $request->password : null,
                ];

                Mail::to($employee->email)->send(new \App\Mail\EmployeeUpdatedMail($details));
            } catch (\Exception $e) {
                \Log::error('Failed to send employee update email: ' . $e->getMessage());
                // Don't fail the request if email sending fails
            }
        }

        return response()->json([
            'success'  => true,
            'message'  => 'Staff updated successfully',
            'employee' => $employee,
        ]);
    }

    public function dashboard_index(Request $request)
    {
        $employee = auth('employee')->user();

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $from = $request->from ? Carbon::parse($request->from) : Carbon::now()->subMonths(6);
        $to   = $request->to ? Carbon::parse($request->to) : Carbon::now();

        // Organizations added by this employee
        $organizations = Organization::where('added_by', $employee->id)
            ->whereBetween('created_at', [$from, $to])
            ->get();

        $totalOrganizations = $organizations->count();

        // Active / Inactive Profiles
        $activeProfiles = DigitalCard::whereIn('organization_id', $organizations->pluck('id'))
            ->where('status', 'active')
            ->count();

        $inactiveProfiles = DigitalCard::whereIn('organization_id', $organizations->pluck('id'))
            ->where('status', '!=', 'active')
            ->count();

        // Revenue from organization_card_purchases
        $totalRevenue = OrganizationCardPurchase::whereIn('organization_id', $organizations->pluck('id'))
            ->whereBetween('created_at', [$from, $to])
            ->sum('total_amount'); // Change 'amount' to your actual revenue column name

        // Monthly Data for Charts (Last 7 months)
        $months = collect(range(0, 6))->map(function ($i) {
            return Carbon::now()->subMonths($i)->format('M');
        })->reverse()->values();

        $growthData = [6, 8, 9, 11, 12, 13, 14]; // Replace with real query if needed

        $revenueData = OrganizationCardPurchase::selectRaw('MONTH(created_at) as month, SUM(total_amount) as total')
            ->whereIn('organization_id', $organizations->pluck('id'))
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('total')
            ->toArray();

        // Fill missing months with 0
        while (count($revenueData) < 7) {
            $revenueData[] = 0;
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'totalOrganizations' => $totalOrganizations,
                'activeProfiles'     => $activeProfiles,
                'inactiveProfiles'   => $inactiveProfiles,
                'totalRevenue'       => number_format($totalRevenue, 2),
                'growthData'         => $growthData,
                'revenueData'        => $revenueData,
            ],
        ]);
    }

    public function profile_update(Request $request)
    {
        $employee = auth('employee')->user();

        if (! $employee) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'name'         => 'nullable|string|max:255',
            'email'        => 'nullable|email|unique:employees,email,' . $employee->id,
            'new_password' => [
                'nullable',

                Password::min(8)->numbers(),
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Update fields
        if ($request->filled('name')) {
            $employee->name = $request->name;
        }

        if ($request->filled('email')) {
            $employee->email = $request->email;
        }

        if ($request->filled('new_password')) {
            $employee->password = Hash::make($request->new_password);
        }

        $employee->save();

        return response()->json([
            'success'  => true,
            'message'  => 'Profile updated successfully',
            'employee' => $employee,
        ]);
    }

    public function show()
    {
        $employee = auth('employee')->user();
        return response()->json([
            'success' => true,
            'user'    => $employee,
        ]);
    }
}
