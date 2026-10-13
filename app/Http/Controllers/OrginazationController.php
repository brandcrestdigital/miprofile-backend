<?php

namespace App\Http\Controllers;

use App\Mail\EmployerCredentialMail;
use App\Models\Brand;
use App\Models\DigitalCard;
use App\Models\Meeting;
use App\Models\Organization;
use App\Models\OrganizationFeature;
use App\Models\StaffCard;
use App\Models\User;
use App\Services\Messenger360Service;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Validator;

class OrginazationController extends Controller
{
    public function index1()
    {
        $organizations = Organization::with([
            'cardPurchases:id,organization_id,total_cards,used_cards',
            'digitalCards:id,organization_id,status',
        ])
            ->latest()
            ->get()
            ->map(function ($org) {

                $totalCards = $org->cardPurchases->sum('total_cards');
                $usedCards  = $org->cardPurchases->sum('used_cards');

                return [
                    'id'              => $org->id,
                    'name'            => $org->name,
                    'email'           => $org->email,
                    'phone'           => $org->phone,

                    'total_cards'     => $totalCards,
                    'used_cards'      => $usedCards,
                    'remaining_cards' => max($totalCards - $usedCards, 0),

                    'active_cards'    => $org->digitalCards->where('status', 'active')->count(),
                    'expired_cards'   => $org->digitalCards->where('status', 'expired')->count(),
                ];
            });

        return response()->json([
            'success' => true,
            'data'    => $organizations,
        ]);
    }

    public function index(Request $request)
    {
        $search  = $request->get('search');
        $perPage = $request->get('per_page', 10); // default 10

        $query = Organization::with([
            // 'cardPurchases:id,organization_id,total_cards,used_cards',
            'cardPurchases:id,organization_id,total_cards,used_cards,is_nfc_card,nfc_price_per_card',
            'digitalCards:id,organization_id,status',
        ]);

        // 🔍 SEARCH (name, email, phone)
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $organizations = $query
            ->latest()
            ->paginate($perPage);

        // 🔄 Transform paginated data
        $organizations->getCollection()->transform(function ($org) {

            $totalCards = $org->cardPurchases->sum('total_cards');
            $usedCards  = $org->cardPurchases->sum('used_cards');

            $isNfcCard = $org->cardPurchases->contains('is_nfc_card', true);

            $nfcCardCount = $org->cardPurchases
                ->where('is_nfc_card', true)
                ->count();

            $nfcPrice = $org->cardPurchases
                ->where('is_nfc_card', true)
                ->sum('nfc_price_per_card');

            return [
                'id'              => $org->id,
                'name'            => $org->name,
                'email'           => $org->email,
                'phone'           => $org->phone,

                'total_cards'     => $totalCards,
                'used_cards'      => $usedCards,
                'remaining_cards' => max($totalCards - $usedCards, 0),

                'active_cards'    => $org->digitalCards->where('status', 'active')->count(),
                'expired_cards'   => $org->digitalCards->where('status', 'expired')->count(),

                // NFC Details
                'is_nfc_card'     => $isNfcCard,
                'nfc_card_count'  => $nfcCardCount,
                'nfc_price'       => $nfcPrice,
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $organizations,
        ]);
    }

    public function index_logined(Request $request)
    {
        $search  = $request->get('search');
        $perPage = $request->get('per_page', 10);

        // Get logged-in user (supports both admin & employee)
        $currentUser = auth('employee')->user() ?? auth()->user();

        if (! $currentUser) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $query = Organization::with([
            'cardPurchases:id,organization_id,total_cards,used_cards,is_nfc_card,nfc_price_per_card',
            'digitalCards:id,organization_id,status',
        ]);

        // 🔥 Filter by added_by (who created the organization)
        $query->where('added_by', $currentUser->id);

        // 🔍 Search (name, email, phone)
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $organizations = $query
            ->latest()
            ->paginate($perPage);

        // Transform data
        $organizations->getCollection()->transform(function ($org) {
            $totalCards = $org->cardPurchases->sum('total_cards');
            $usedCards  = $org->cardPurchases->sum('used_cards');

            return [
                'id'              => $org->id,
                'name'            => $org->name,
                'email'           => $org->email,
                'phone'           => $org->phone,

                'total_cards'     => $totalCards,
                'used_cards'      => $usedCards,
                'remaining_cards' => max($totalCards - $usedCards, 0),

                'active_cards'    => $org->digitalCards->where('status', 'active')->count(),
                'expired_cards'   => $org->digitalCards->where('status', 'expired')->count(),

                'is_nfc_card'     => $org->cardPurchases->contains('is_nfc_card', true),
                'nfc_card_count'  => $org->cardPurchases->where('is_nfc_card', true)->count(),
                'nfc_price'       => $org->cardPurchases->where('is_nfc_card', true)->sum('nfc_price_per_card'),
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $organizations,
        ]);
    }

    /**
     * Create Organization
     *
     * @group Organization
     *
     * @bodyParam name string required Organization name
     * @bodyParam email string required Organization email
     * @bodyParam phone string Organization phone
     *
     * @response 201 {
     *   "success": true,
     *   "data": {}
     * }
     */

    public function store_old(Request $request, Messenger360Service $whatsapp)
    {
        $validator = Validator::make($request->all(), [
            'organization_name' => 'required|string|max:255',

            'email'             => [
                'required',
                'email',
                // Rule::unique('organizations', 'email'),
                // Rule::unique('users', 'email'),
            ],

            'phone'             => [
                'required',
                'string',
                'max:15',
                // Rule::unique('organizations', 'phone'),
                // Rule::unique('users', 'phone'),
            ],

            // 'password'          => 'required|string|min:6',
        ]);

        $userByEmail = User::where('email', $request->email)->first();

        if ($userByEmail) {

            $role = $userByEmail->role == 'employee'
                ? 'Employee'
                : 'Employer';

            return response()->json([
                'success' => false,
                'message' => "Email already registered as {$role}",
            ], 422);
        }

        // Check phone in users table
        $userByPhone = User::where('phone', $request->phone)->first();

        if ($userByPhone) {

            $role = $userByPhone->role == 'employee'
                ? 'Employee'
                : 'Employer';

            return response()->json([
                'success' => false,
                'message' => "Phone number already registered as {$role}",
            ], 422);
        }

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error'   => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {

            $currentUser = auth()->user();

            // 1️⃣ Create Organization
            $organization = Organization::create([
                'name'     => $request->organization_name,
                'email'    => $request->email,
                'phone'    => $request->phone,
                'added_by' => $currentUser?->id,
            ]);

            // $password = 123456;

            $password = Str::password(
                length: 10,
                letters: true,
                numbers: true,
                symbols: true
            );
            // 2️⃣ Create Admin User
            $admin = User::create([
                'name'            => $request->organization_name,
                'email'           => $request->email,
                'phone'           => $request->phone,
                'password'        => Hash::make($password),
                'role'            => 'employeer',
                'organization_id' => $organization->id,
            ]);

            // 3️⃣ Create Brand
            Brand::create([
                'organization_id'       => $organization->id,
                'brand_name'            => $request->organization_name,
                'template_change'       => false,
                'cover_change'          => false,
                'custom_community_logo' => false,
            ]);

            Mail::mailer('smtp')->to($admin->email)
                ->send(new EmployerCredentialMail($admin, $password));

            $setupLink = env('REACT_APP_URL') . '/login';

            $message = "Hi {$admin->name} ✨

You're in!

Welcome to Mi Profile, where your professional identity gets a smarter upgrade.

Your account has been created successfully. Set up your Mi Profile, make it share-ready, and start connecting beyond contacts.

🔗 Open your setup:
{$setupLink}

Need help getting started? Our team is here to assist you.

Team Mi Profile
Connect Beyond Contacts.";

            $whatsapp->send($admin->phone, $message);

            DB::commit();

            return response()->json([
                'success'      => true,
                'message'      => 'Organization registered successfully',
                'organization' => $organization,
                'admin'        => $admin,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Registration failed',
                'error'   => $e->getMessage(), // remove in production
            ], 500);
        }
    }

    public function store_v1(Request $request, Messenger360Service $whatsapp)
    {
        // ====================== DEBUG AUTH ======================
        $currentUser = auth()->user();

        if (! $currentUser) {
            return response()->json([
                'success' => false,
                'error'   => 'AUTH_ERROR',
                'message' => 'User not authenticated. Please login again.',
            ], 401);
        }
        // =======================================================

        $validator = Validator::make($request->all(), [
            'organization_name' => 'required|string|max:255',
            'email'             => 'required|email',
            'phone'             => 'required|string|max:15',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error'   => $validator->errors(),
            ], 422);
        }

        // Check existing email/phone
        $userByEmail = User::where('email', $request->email)->first();
        if ($userByEmail) {
            $role = $userByEmail->role == 'employee' ? 'Employee' : 'Employer';
            return response()->json([
                'success' => false,
                'message' => "Email already registered as {$role}",
            ], 422);
        }

        $userByPhone = User::where('phone', $request->phone)->first();
        if ($userByPhone) {
            $role = $userByPhone->role == 'employee' ? 'Employee' : 'Employer';
            return response()->json([
                'success' => false,
                'message' => "Phone number already registered as {$role}",
            ], 422);
        }

        DB::beginTransaction();

        try {
            // 1️⃣ Create Organization
            $organization = Organization::create([
                'name'     => $request->organization_name,
                'email'    => $request->email,
                'phone'    => $request->phone,
                'added_by' => $currentUser->id, // Safe now
            ]);

            $password = Str::password(10, true, true, true);

            // 2️⃣ Create Admin User
            $admin = User::create([
                'name'            => $request->organization_name,
                'email'           => $request->email,
                'phone'           => $request->phone,
                'password'        => Hash::make($password),
                'role'            => 'employeer',
                'organization_id' => $organization->id,
            ]);

            // 3️⃣ Create Brand
            Brand::create([
                'organization_id'       => $organization->id,
                'brand_name'            => $request->organization_name,
                'template_change'       => false,
                'cover_change'          => false,
                'custom_community_logo' => false,
            ]);

            // Send Credentials
            Mail::mailer('smtp')->to($admin->email)
                ->send(new EmployerCredentialMail($admin, $password));

            $setupLink = env('REACT_APP_URL') . '/login';

            $message = "Hi {$admin->name} ✨\n\nYou're in!\n\nWelcome to Mi Profile...\n\n🔗 {$setupLink}\n\nTeam Mi Profile";

            $whatsapp->send($admin->phone, $message);

            DB::commit();

            return response()->json([
                'success'      => true,
                'message'      => 'Organization registered successfully',
                'organization' => $organization,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Registration failed',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request, Messenger360Service $whatsapp)
    {

        try {
            $currentUser = JWTAuth::parseToken()->authenticate();
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error'   => 'AUTH_ERROR',
                'message' => 'Invalid or expired token. Please login again.',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'organization_name' => 'required|string|max:255',
            'email'             => 'required|email',
            'phone'             => 'required|string|max:15',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error'   => $validator->errors(),
            ], 422);
        }

        // Duplicate checks
        if (User::where('email', $request->email)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Email already registered',
            ], 422);
        }


        DB::beginTransaction();

        try {
            $organization = Organization::create([
                'name'     => $request->organization_name,
                'email'    => $request->email,
                'phone'    => $request->phone,
                'added_by' => $currentUser->id,
            ]);

            $password = Str::password(10, true, true, true);

            $admin = User::create([
                'name'            => $request->organization_name,
                'email'           => $request->email,
                'phone'           => $request->phone,
                'password'        => Hash::make($password),
                'role'            => 'employeer',
                'organization_id' => $organization->id,
            ]);

            Brand::create([
                'organization_id'       => $organization->id,
                'brand_name'            => $request->organization_name,
                'template_change'       => false,
                'cover_change'          => false,
                'custom_community_logo' => false,
            ]);

            // Send Email & WhatsApp
            Mail::mailer('smtp')->to($admin->email)
                ->send(new EmployerCredentialMail($admin, $password));

            $setupLink = env('REACT_APP_URL') . '/login';
            $message   = "Hi {$admin->name} ✨\n\nYou're in!\n\n🔗 {$setupLink}\n\nTeam Mi Profile";

            $whatsapp->send($admin->phone, $message);

            DB::commit();

            return response()->json([
                'success'      => true,
                'message'      => 'Organization registered successfully',
                'organization' => $organization,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Registration failed',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }



    public function update_org_employee(Request $request, $id)
    {
        try {
            $currentUser = JWTAuth::parseToken()->authenticate();
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error'   => 'AUTH_ERROR',
                'message' => 'Invalid or expired token. Please login again.',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'organization_name' => 'required|string|max:255',
            'email'             => 'required|email',
            'phone'             => 'required|string|max:15',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error'   => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {

            $organization = Organization::findOrFail($id);

            // Check duplicate email except current organization
            if (
                User::where('email', $request->email)
                ->where('organization_id', '!=', $organization->id)
                ->exists()
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email already registered',
                ], 422);
            }

            // Check duplicate phone except current organization
            // if (
            //     User::where('phone', $request->phone)
            //         ->where('organization_id', '!=', $organization->id)
            //         ->exists()
            // ) {
            //     return response()->json([
            //         'success' => false,
            //         'message' => 'Phone number already registered',
            //     ], 422);
            // }

            // Update Organization
            $organization->update([
                'name'     => $request->organization_name,
                'email'    => $request->email,
                'phone'    => $request->phone,
                'added_by' => $currentUser->id,
            ]);

            // Update Employer User
            $admin = User::where('organization_id', $organization->id)
                ->where('role', 'employeer')
                ->first();

            if ($admin) {
                $admin->update([
                    'name'  => $request->organization_name,
                    'email' => $request->email,
                    'phone' => $request->phone,
                ]);
            }

            // Update Brand
            $brand = Brand::where('organization_id', $organization->id)->first();

            if ($brand) {
                $brand->update([
                    'brand_name' => $request->organization_name,
                ]);
            }

            DB::commit();

            return response()->json([
                'success'      => true,
                'message'      => 'Organization updated successfully',
                'organization' => $organization,
            ], 200);
        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Update failed',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
    public function show($id)
    {

       

        return response()->json([
            'success' => true,
            'data'    => Organization::findOrFail($id),
        ]);
    }

    //  Update from orginazation login
    public function updateMyOrg(Request $request)
    {
        $user = auth()->user();

        $organization = $user->organization;

        if (! $organization) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not found.',
            ], 404);
        }

        $organization = Organization::findOrFail($user->organization_id);

        // $organization = Organization::findOrFail($id);

        $adminUser = User::where('organization_id', $organization->id)
            ->where('role', 'employeer')
            ->first();

        $validator = Validator::make($request->all(), [
            'name'     => 'required|string|max:255',

            'email'    => [
                'required',
                'email',
                // Rule::unique('organizations', 'email')->ignore($organization->id),
                // Rule::unique('users', 'email')->ignore(optional($adminUser)->id),
            ],

            'phone'    => [
                'nullable',
                'string',
                'max:15',
                // Rule::unique('organizations', 'phone')->ignore($organization->id),
                // Rule::unique('users', 'phone')->ignore(optional($adminUser)->id),
            ],

            'password' => 'nullable|string|min:6',
        ]);

        // Check email exists in users table except current employer
        $userByEmail = User::where('email', $request->email)
            ->where('id', '!=', optional($adminUser)->id)
            ->first();

        if ($userByEmail) {

            $role = $userByEmail->role === 'employee'
                ? 'Employee'
                : 'Employer';

            return response()->json([
                'success' => false,
                'error'   => [
                    'email' => [
                        "Email already registered as {$role}",
                    ],
                ],
            ], 422);
        }

        // Check phone exists in users table except current employer
        if ($request->filled('phone')) {

            $userByPhone = User::where('phone', $request->phone)
                ->where('id', '!=', optional($adminUser)->id)
                ->first();

            if ($userByPhone) {

                $role = $userByPhone->role === 'employee'
                    ? 'Employee'
                    : 'Employer';

                return response()->json([
                    'success' => false,
                    'error'   => [
                        'phone' => [
                            "Phone number already registered as {$role}",
                        ],
                    ],
                ], 422);
            }
        }

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {

            $organization->update([
                'name'  => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
            ]);

            // Update brand name
            Brand::updateOrCreate(
                ['organization_id' => $organization->id],
                [
                    'brand_name' => $request->name,
                ]
            );

            if ($adminUser) {

                // ✅ Define first
                $userData = [
                    'name'  => $request->name,
                    'email' => $request->email,
                    'phone' => $request->phone,
                ];

                // ✅ Add password only if provided
                if ($request->filled('password')) {
                    $userData['password'] = Hash::make($request->password);
                }

                $adminUser->update($userData);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'data'    => $organization,
            ]);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function showMyOrg()
    {
        $user = auth()->user();

        if (! $user || $user->role !== 'employeer') {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. Only employers can access this resource.',
            ], 403);
        }

        $organization = Organization::findOrFail($user->organization_id);

        return response()->json([
            'success' => true,
            'data'    => $organization,
        ]);
    }

    public function update(Request $request, $id)
    {
        $organization = Organization::findOrFail($id);

        $adminUser = User::where('organization_id', $organization->id)
            ->where('role', 'employeer')
            ->first();

        $validator = Validator::make($request->all(), [
            'name'     => 'required|string|max:255',
            'email'    => 'required|email',
            'phone'    => 'nullable|string|max:15',
            'password' => 'nullable|string|min:6',
        ]);

        // Check email exists in users table except current employer
        $userByEmail = User::where('email', $request->email)
            ->where('id', '!=', optional($adminUser)->id)
            ->first();

        if ($userByEmail) {
            $role = $userByEmail->role === 'employee' ? 'Employee' : 'Employer';
            return response()->json([
                'success' => false,
                'error'   => [
                    'email' => ["Email already registered as {$role}"],
                ],
            ], 422);
        }

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            $oldEmail = $organization->email;
            $oldPhone = $organization->phone;

            // Update Organization
            $organization->update([
                'name'  => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
            ]);

            // Update Brand Name
            Brand::updateOrCreate(
                ['organization_id' => $organization->id],
                ['brand_name' => $request->name]
            );

            $changes           = [];
            $isPasswordChanged = false;

            if ($adminUser) {
                $userData = [
                    'name'  => $request->name,
                    'email' => $request->email,
                    'phone' => $request->phone,
                ];

                // Check what changed
                if ($oldEmail !== $request->email) {
                    $changes[] = 'Email';
                }
                if ($oldPhone !== $request->phone) {
                    $changes[] = 'Phone Number';
                }
                if ($request->filled('password')) {
                    $userData['password'] = Hash::make($request->password);
                    $changes[]            = 'Password';
                    $isPasswordChanged    = true;
                }

                $adminUser->update($userData);
            }

            DB::commit();

            // Send Email Notification if any sensitive info changed
            if (! empty($changes) && $adminUser) {
                try {
                    $details = [
                        'name'              => $adminUser->name,
                        'email'             => $adminUser->email,
                        'changes'           => $changes,
                        'isPasswordChanged' => $isPasswordChanged,
                        'newPassword'       => $request->filled('password') ? $request->password : null,
                    ];

                    Mail::to($adminUser->email)->send(new \App\Mail\OrganizationUpdatedMail($details));
                } catch (\Exception $mailException) {
                    \Log::error('Failed to send organization update email: ' . $mailException->getMessage());
                    // Don't fail the request if email fails
                }
            }

            return response()->json([
                'success' => true,
                'data'    => $organization,
                'message' => 'Organization updated successfully' . (! empty($changes) ? ' and notification email sent.' : ''),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        $organization = Organization::findOrFail($id);

        // Permanently delete all users of this organization
        User::where('organization_id', $organization->id)
            ->forceDelete();

        // Permanently delete the organization
        $organization->forceDelete();

        return response()->json([
            'success' => true,
            'message' => 'Organization and users deleted permanently',
        ]);
    }
    public function show_access($id)
    {
        $organization = Organization::with([
            'cardPurchases' => fn($q) => $q->latest(),
            'features',
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $organization,
        ]);
    }

    public function updateFeatures(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'templates_module'       => 'required|boolean',
            'community_logos'        => 'required|boolean',
            'custom_branding'        => 'required|boolean',
            'template_customization' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        // ✅ Get validated data
        $validated = $validator->validated();

        $features = OrganizationFeature::updateOrCreate(
            ['organization_id' => $id],
            $validated
        );

        return response()->json([
            'success' => true,
            'message' => 'Features updated successfully',
            'data'    => $features,
        ]);
    }

    public function dashboard2()
    {

        $orgId = auth()->user()->organization_id;

        $totalCards = DigitalCard::where('organization_id', $orgId)->count();

        $activeCards = DigitalCard::where('organization_id', $orgId)
            ->where('status', 'active')
            ->whereDate('expires_at', '>=', now())
            ->count();

        $expiredCards = DigitalCard::where('organization_id', $orgId)
            ->where('status', 'expired')
            ->count();

        $allocatedCards = DigitalCard::where('organization_id', $orgId)
            ->where('user_id', '!=', null)
            ->count();

        $NotallocatedCards = DigitalCard::where('organization_id', $orgId)
            ->where('user_id', '=', null)
            ->count();

        $months = collect(range(0, 7))->map(function ($i) {
            return Carbon::now()->subMonths(7 - $i)->format('Y-m');
        });

        $cardGrowth = StaffCard::select(
            DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
            DB::raw("COUNT(*) as total")
        )
            ->where('organization_id', $orgId)
            ->where('created_at', '>=', Carbon::now()->subMonths(7))
            ->groupBy('month')
            ->pluck('total', 'month');

        $chart = $months->map(function ($month) use ($cardGrowth) {
            return [
                'month' => Carbon::createFromFormat('Y-m', $month)->format('M'),
                'cards' => $cardGrowth[$month] ?? 0,
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => [

                'stats' => [
                    'total_cards'  => $totalCards,
                    'active_cards' => $activeCards,
                    'NotAllocated' => $NotallocatedCards,
                    'allocated'    => $allocatedCards,
                ],
                'chart' => $chart,
            ],
        ]);
    }



    public function dashboard()
    {
        $orgId = auth()->user()->organization_id;

        // Get all user IDs in this organization
        $userIds = User::where('organization_id', $orgId)->pluck('id');

        // Cards
        $totalCards = DigitalCard::where('organization_id', $orgId)->count();

        $activeCards = DigitalCard::where('organization_id', $orgId)
            ->where('status', 'active')
            ->whereDate('expires_at', '>=', now())
            ->count();

        $allocatedCards = DigitalCard::where('organization_id', $orgId)
            ->whereNotNull('user_id')
            ->count();

        $notAllocatedCards = DigitalCard::where('organization_id', $orgId)
            ->whereNull('user_id')
            ->count();

        // ================= TOTAL VIEWS =================
        $totalViews = \DB::table('profile_analytics')
            ->whereIn('card_id', function ($query) use ($orgId) {
                $query->select('id')
                    ->from('staff_cards') // or 'staff_cards' if that's your table
                    ->where('organization_id', $orgId);
            })
            ->where('action', 'view')
            ->count();

        // Meetings
        $totalMeetings = Meeting::whereIn('user_id', $userIds)->count();

        // Card Growth Chart
        $months = collect(range(0, 7))->map(function ($i) {
            return Carbon::now()->subMonths(7 - $i)->format('Y-m');
        });

        $cardGrowth = StaffCard::select(
            DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
            DB::raw("COUNT(*) as total")
        )
            ->where('organization_id', $orgId)
            ->where('created_at', '>=', Carbon::now()->subMonths(7))
            ->groupBy('month')
            ->pluck('total', 'month');

        $chart = $months->map(function ($month) use ($cardGrowth) {
            return [
                'month' => Carbon::createFromFormat('Y-m', $month)->format('M'),
                'cards' => $cardGrowth[$month] ?? 0,
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => [
                'stats' => [
                    'total_cards'    => $totalCards,
                    'active_cards'   => $activeCards,
                    'allocated'      => $allocatedCards,
                    'NotAllocated'   => $notAllocatedCards,
                    'total_meetings' => $totalMeetings,
                    'total_views'    => $totalViews, // ← Added
                ],
                'chart' => $chart,
            ],
        ]);
    }

    public function showBySlug($slug)
    {
        $organization = Organization::where('slug', $slug)->firstOrFail();

        return response()->json([
            'success' => true,
            'data'    => $organization,
        ]);
    }
}
