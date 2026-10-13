<?php
namespace App\Http\Controllers;

use App\Mail\CardRenwalMail;
use App\Mail\StaffCardCreatedMail;
use App\Models\Brand;
use App\Models\DigitalCard;
use App\Models\Organization;
use App\Models\OrganizationCardPurchase;
use App\Models\ProfileAnalytics;
use App\Models\StaffCard;
use App\Models\User;
use App\Services\WebpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StaffCardController extends Controller
{
    public function index1()
    {
        $user = auth()->user();

        // Get all employees under same organization
        $employees = User::where('organization_id', $user->organization_id)
            ->where('role', 'employee')
            ->get();

        // Assigned = employees with card
        $assigned = $employees->whereNotNull('card_id')->values();

        // Unassigned = employees without card
        $unassignedCount = $employees->whereNull('card_id')->count();

        return response()->json([
            'success' => true,
            'data'    => [
                'assigned'         => $assigned,
                'unassigned_slots' => $unassignedCount,
            ],
        ]);
    }

    public function store(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'name'               => 'required|string',
            'designation'        => 'nullable|string',
            'phone'              => 'nullable|string',
            'email'              => 'nullable|email',
            'website'            => 'nullable|string',
            'company_name'       => 'nullable|string',
            'company_email'      => [
                'required',
                'email',
                // 'unique:users,email',
            ],

            'profile_image'      => 'nullable|image',
            'cover_image'        => 'nullable|image',

            'whatsapp'           => 'nullable|digits:10',
            'description'        => 'nullable|string',
            'category'           => 'nullable|string',
            'completion_score'   => 'nullable|integer|min:0|max:100',
            'company_phone'      => 'nullable|digits:10',
            'alternate_phone'    => 'nullable|digits:10',

            'services'           => 'nullable|array',
            'services.*.heading' => 'required|string',
            'services.*.items'   => 'nullable|array',
            'community_images.*' => 'nullable|image',
            'brochures.*'        => 'nullable|file|mimes:pdf',
            'linkedin'           => 'nullable|url',
            'instagram'          => 'nullable|url',
            'facebook'           => 'nullable|url',
            'youtube'            => 'nullable|url',
            'pincode'            => 'nullable|string',
            'country'            => 'nullable|string',
            'phone_code'         => 'nullable|string',
            'state'              => 'nullable|string',
            'city'               => 'nullable|string',
            'address'            => 'nullable|string',
            'additional_phones'  => 'nullable|array',
        ]);

        $validator->after(function ($validator) use ($request) {

            $user = User::where('email', $request->company_email)->first();

            if ($user) {

                if ($user->role === 'employer') {

                    $validator->errors()->add(
                        'company_email',
                        'Email already registered as organization.'
                    );

                } elseif ($user->role === 'employee') {

                    $validator->errors()->add(
                        'company_email',
                        'Email already registered as employee.'
                    );

                } else {

                    $validator->errors()->add(
                        'company_email',
                        'Email already registered.'
                    );
                }
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $admin = auth()->user();
        $orgId = $admin->organization_id;

        DB::beginTransaction();

        try {

            /* ================= 1️⃣ CREATE USER ================= */

            $user = User::create([
                'organization_id' => $orgId,
                'name'            => $request->name,
                'email'           => $request->company_email,
                'phone'           => $request->phone,
                'role'            => 'employee',
                'password'        => bcrypt('123456'),
            ]);

            /* ================= 2️⃣ GET AVAILABLE DIGITAL CARD ================= */

            $digitalCard = DigitalCard::where('organization_id', $orgId)
                ->whereNull('user_id')
                ->where('status', 'active')
                ->whereDate('expires_at', '>=', now())
                ->first();

            if (! $digitalCard) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'No available digital cards remaining',
                ], 400);
            }

            // Generate Base Slug
            $baseSlug = Str::slug($request->name);

// Make Slug Unique
            $slug  = $baseSlug;
            $count = 1;

            while (StaffCard::where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . $count++;
            }

            /* ================= 3️⃣ CREATE STAFF CARD ================= */

            $staffCard = StaffCard::create([
                'organization_id'   => $orgId,
                'user_id'           => $user->id,
                'purchase_id'       => $digitalCard->purchase_id,
                'slug'              => $slug,
                'name'              => $request->name,
                'designation'       => $request->designation,
                'phone'             => $request->phone,
                'email'             => $request->email,
                'website'           => $request->website,
                'company_name'      => $request->company_name,
                'company_email'     => $request->company_email,

                'company_phone'     => $request->company_phone,
                'bio'               => $request->description,
                'alternate_phone'   => $request->alternate_phone,
                'whatsapp_number'   => $request->whatsapp,
                'category'          => $request->category,
                'completion_score'  => $request->completion_score,

                'pincode'           => $request->pincode,
                'country'           => $request->country,
                'phone_code'        => $request->phone_code,
                'state'             => $request->state,
                'city'              => $request->city,
                'address'           => $request->address,

                'additional_phones' => $request->additional_phones
                    ? json_encode($request->additional_phones)
                    : null,

                'linkedin'          => $request->linkedin,
                'instagram'         => $request->instagram,
                'facebook'          => $request->facebook,
                'youtube'           => $request->youtube,
            ]);

            /* ================= 4️⃣ FILE UPLOAD ================= */

            // PROFILE IMAGE (WEBP)
            if ($request->hasFile('profile_image')) {
                $file     = $request->file('profile_image');
                $filename = 'profile_' . Str::random(10) . '.webp';
                $destPath = public_path('storage/staff/profile/' . $filename);

                WebpService::convert($file->getPathname(), $destPath, 75, 400, 400);

                $staffCard->profile_image = 'staff/profile/' . $filename;
            }

            // COVER IMAGE (WEBP)
            if ($request->hasFile('cover_image')) {
                $file     = $request->file('cover_image');
                $filename = 'cover_' . Str::random(10) . '.webp';
                $destPath = public_path('storage/staff/cover/' . $filename);

                WebpService::convert($file->getPathname(), $destPath, 80, 1400, 600);

                $staffCard->cover_image = 'staff/cover/' . $filename;
            }

            $staffCard->save();

            // SERVICES
            if ($request->services) {

                foreach ($request->services as $service) {

                    // \Log::info('Before Service Create', [
                    //     'service' => $service,
                    // ]);

                    // $created = $staffCard->services()->create([
                    //     'service_name' => $service['heading'] ?? '',
                    // ]);

                    // \Log::info('After Service Create', [
                    //     'id' => $created->id,
                    // ]);

                    $staffCard->services()->create([
                        'service_details' => json_encode($service),
                    ]);

                }
            }

            // COMMUNITY IMAGES (WEBP)
            if ($request->hasFile('community_images')) {
                foreach ($request->file('community_images') as $index => $file) {

                    $filename = 'community_' . Str::random(10) . '.webp';
                    $destPath = public_path('storage/staff/community/' . $filename);

                    WebpService::convert($file->getPathname(), $destPath, 75, 300, 300);

                    $staffCard->communityImages()->create([
                        'image_path' => 'staff/community/' . $filename,
                        'name'       => $request->community_names[$index] ?? null,
                    ]);
                }
            }

            // BROCHURES (PDF)
            if ($request->hasFile('brochures')) {
                foreach ($request->file('brochures') as $index => $file) {

                    $path = $file->store('staff/brochures', 'public');

                    $staffCard->brochures()->create([
                        'file_path' => $path,
                        'name'      => $request->brochure_names[$index] ?? null,
                    ]);
                }
            }

            /* ================= 5️⃣ ASSIGN DIGITAL CARD ================= */

            $digitalCard->update([
                'user_id'   => $user->id,
                'issued_at' => now(),
            ]);

            OrganizationCardPurchase::where('id', $digitalCard->purchase_id)
                ->increment('used_cards');

            DB::commit();

            /* ================= 6️⃣ SEND WELCOME EMAIL ================= */

            try {

                $organization = Organization::findOrFail($orgId);

                // $profileLink = sprintf(
                //     'https://org.miprofile.in/%s/%s',
                //     $organization->slug,
                //     $staffCard->slug
                // );

                $profileLink = sprintf(
                    '%s/%s/%s',
                    rtrim(env('REACT_APP_URL'), '/'),
                    $organization->slug,
                    $staffCard->slug
                );

                \Log::info('Staff Profile Link Generated', [
                    'organization_id'   => $organization->id,
                    'organization_slug' => $organization->slug,
                    'staff_slug'        => $staffCard->slug,
                    'profile_link'      => $profileLink,
                ]);

                $mailData = [
                    'user_name'         => $user->name,
                    'organization_name' => $organization->name,
                    'designation'       => $staffCard->designation ?? 'Employee',
                    'profile_link'      => $profileLink,
                    'login_url'         => 'https://org.miprofile.in/login',
                ];

                \Log::info('Staff Welcome Mail Data', $mailData);

                Mail::to($user->email)->send(
                    new StaffCardCreatedMail($mailData)
                );

                $message = "Hi {$user->name} ✨

Your Mi Profile is now created.

Your digital identity is ready to set up, share, and connect beyond contacts.

No paper.
No repeated introductions.
Just one smart profile link.

🔗 Your Mi Profile:
{$profileLink}

Complete your setup here:
" . env('REACT_APP_URL') . "/login

Add your photo, contact details, company info, services, social links, and meeting booking option to make your Mi Profile fully share-ready.

Team Mi Profile
Connect Beyond Contacts.";

                $messenger = app(\App\Services\Messenger360Service::class);

                $phones = array_unique(array_filter([
                    $request->company_phone,
                    $request->whatsapp,
                ]));

                foreach ($phones as $phone) {
                    $messenger->send($phone, $message);
                }

            } catch (\Exception $mailException) {

                \Log::error('Staff Welcome Email Failed', [
                    'error' => $mailException->getMessage(),
                    'line'  => $mailException->getLine(),
                    'file'  => $mailException->getFile(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Staff created and digital card assigned successfully',
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            // return response()->json([
            //     'success' => false,
            //     'message' => 'Something went wrong',

            // ], 500);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(), // 👈 show real error
                'line'    => $e->getLine(),    // optional
                'file'    => $e->getFile(),    // optional
            ], 500);
        }
    }

    public function index()
    {

        $admin = auth()->user();

        $cards = StaffCard::with(['services', 'brochures', 'communityImages'])
            ->where('organization_id', $admin->organization_id)
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $cards,
        ]);
    }

    public function myCard()
    {
        $user = auth()->user();

        $card = StaffCard::with(['services', 'brochures', 'communityImages'])
            ->where('user_id', $user->id)
            ->first();

        if (! $card) {
            return response()->json([
                'success' => false,
                'message' => 'No card assigned',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $card,
        ]);
    }

    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $card = StaffCard::with(['user', 'services', 'brochures', 'communityImages'])
                ->where('slug', $id)
                ->first();

            if (! $card) {
                return response()->json([
                    'success' => false,
                    'message' => 'Card not found',
                ], 404);
            }

            /* ================= VALIDATION ================= */
            $validator = Validator::make($request->all(), [
                'name'                 => 'required|string',
                'email'                => 'nullable|email',
                'phone'                => ['nullable', 'string', Rule::unique('users', 'phone')->ignore($card->user_id)],
                'password'             => 'nullable|min:6',
                'designation'          => 'nullable|string',
                'website'              => 'nullable|string',
                'company_name'         => 'nullable|string',
                'company_email'        => 'nullable|email',
                'category'             => 'nullable|string',
                'completion_score'     => 'nullable|integer|min:0|max:100',

                'services'             => 'nullable|array',
                'services.*.heading'   => 'required|string',

                'profile_image'        => 'nullable|image',
                'cover_image'          => 'nullable|image',
                'community_images.*'   => 'nullable|image',
                'brochures.*'          => 'nullable|file|mimes:pdf|max:10240',

                'remove_profile_image' => 'nullable|boolean',
                'remove_cover_image'   => 'nullable|boolean',
            ], [
                'brochures.*.max'   => 'Each brochure must not exceed 10 MB.',
                'brochures.*.mimes' => 'Only PDF files are allowed.',
            ]);

            // Company email unique validation
            $validator->after(function ($validator) use ($request, $card) {
                if ($request->filled('company_email')) {
                    $exists = User::where('email', $request->company_email)
                        ->where('id', '!=', $card->user_id)
                        ->exists();

                    if ($exists) {
                        $validator->errors()->add('company_email', 'This email is already registered.');
                    }
                }
            });

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first(),
                ], 422);
            }

            /* ================= HANDLE IMAGE REMOVAL ================= */
            if ($request->boolean('remove_profile_image') && $card->profile_image) {
                if (Storage::disk('public')->exists($card->profile_image)) {
                    Storage::disk('public')->delete($card->profile_image);
                }
                $card->profile_image = null;
            }

            if ($request->boolean('remove_cover_image') && $card->cover_image) {
                if (Storage::disk('public')->exists($card->cover_image)) {
                    Storage::disk('public')->delete($card->cover_image);
                }
                $card->cover_image = null;
            }

            /* ================= DELETE OLD BROCHURES ================= */
            $deletedBrochureIds = $request->input('deleted_brochure_ids', []);
            if (! empty($deletedBrochureIds)) {
                $brochures = $card->brochures()->whereIn('id', $deletedBrochureIds)->get();
                foreach ($brochures as $brochure) {
                    if ($brochure->file_path && Storage::disk('public')->exists($brochure->file_path)) {
                        Storage::disk('public')->delete($brochure->file_path);
                    }
                    $brochure->forceDelete();
                }
            }

            /* ================= DELETE OLD COMMUNITY IMAGES ================= */
            $deletedCommunityIds = $request->input('deleted_community_image_ids', []);
            if (! empty($deletedCommunityIds)) {
                $images = $card->communityImages()->whereIn('id', $deletedCommunityIds)->get();
                foreach ($images as $image) {
                    if ($image->image_path && Storage::disk('public')->exists($image->image_path)) {
                        Storage::disk('public')->delete($image->image_path);
                    }
                    $image->forceDelete();
                }
            }

            /* ================= UPDATE STAFF CARD ================= */
            $card->update([
                'name'              => $request->name,
                'designation'       => $request->designation,
                'phone'             => $request->phone,
                'email'             => $request->email,
                'website'           => $request->website,
                'company_name'      => $request->company_name,
                'company_email'     => $request->company_email,
                'company_phone'     => $request->company_phone,
                'bio'               => $request->description ?? $request->bio,
                'alternate_phone'   => $request->alternate_phone,
                'whatsapp_number'   => $request->whatsapp,
                'completion_score'  => $request->completion_score,
                'category'          => $request->category,

                'pincode'           => $request->pincode,
                'country'           => $request->country,
                'phone_code'        => $request->phone_code,
                'state'             => $request->state,
                'city'              => $request->city,
                'address'           => $request->address,

                'additional_phones' => $request->additional_phones
                    ? json_encode($request->additional_phones)
                    : null,

                'linkedin'          => $request->linkedin,
                'instagram'         => $request->instagram,
                'facebook'          => $request->facebook,
                'youtube'           => $request->youtube,
            ]);

            /* ================= UPDATE USER ================= */
            if ($card->user) {
                $userData = [
                    'name'  => $request->name,
                    'email' => $request->company_email,
                    'phone' => $request->phone,
                ];

                if ($request->filled('password')) {
                    $userData['password'] = bcrypt($request->password);
                }

                $card->user->update($userData);
            }

            /* ================= UPLOAD NEW PROFILE IMAGE ================= */
            if ($request->hasFile('profile_image')) {
                // Delete old one if not already removed
                if ($card->profile_image && Storage::disk('public')->exists($card->profile_image)) {
                    Storage::disk('public')->delete($card->profile_image);
                }

                $file     = $request->file('profile_image');
                $filename = 'profile_' . Str::random(12) . '.webp';
                $path     = 'staff/profile/' . $filename;

                WebpService::convert($file->getPathname(), public_path('storage/' . $path), 75, 400, 400);
                $card->profile_image = $path;
            }

            /* ================= UPLOAD NEW COVER IMAGE ================= */
            if ($request->hasFile('cover_image')) {
                if ($card->cover_image && Storage::disk('public')->exists($card->cover_image)) {
                    Storage::disk('public')->delete($card->cover_image);
                }

                $file     = $request->file('cover_image');
                $filename = 'cover_' . Str::random(12) . '.webp';
                $path     = 'staff/cover/' . $filename;

                WebpService::convert($file->getPathname(), public_path('storage/' . $path), 80, 1400, 600);
                $card->cover_image = $path;
            }

            $card->save();

            /* ================= ADD NEW BROCHURES ================= */
            if ($request->hasFile('brochures')) {
                foreach ($request->file('brochures') as $index => $file) {
                    $path = $file->store('staff/brochures', 'public');

                    $card->brochures()->create([
                        'file_path' => $path,
                        'name'      => $request->brochure_names[$index] ?? $file->getClientOriginalName(),
                    ]);
                }
            }

            /* ================= ADD NEW COMMUNITY IMAGES ================= */
            if ($request->hasFile('community_images')) {
                foreach ($request->file('community_images') as $index => $file) {
                    $filename = 'community_' . Str::random(12) . '.webp';
                    $path     = 'staff/community/' . $filename;

                    // Convert to WebP (recommended for consistency)
                    WebpService::convert($file->getPathname(), public_path('storage/' . $path), 75, 800, 800);

                    $card->communityImages()->create([
                        'image_path' => $path,
                        'name'       => $request->community_image_names[$index] ?? $file->getClientOriginalName(),
                        // Add any other fields like 'caption', 'sort_order' if your model has them
                    ]);
                }
            }

            DB::commit();

            $this->sendUpdateNotification($card);

            return response()->json([
                'success' => true,
                'message' => 'Staff card updated successfully',
                'data'    => $card->fresh(['user', 'services', 'brochures', 'communityImages']),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send email notification after staff card update
     */
    private function sendUpdateNotification(StaffCard $card)
    {
        if (! $card->user || ! $card->user->email) {
            return; // Skip if no user email
        }

        try {
            \Mail::to($card->user->email)->send(new \App\Mail\StaffCardUpdated($card));

            // Optional: Also notify admin
            // \Mail::to(config('mail.admin_email'))->send(new \App\Mail\StaffCardUpdated($card));
        } catch (\Exception $e) {
            \Log::error('Failed to send staff card update email: ' . $e->getMessage());
            // Don't throw exception - we don't want to rollback the update
        }
    }

    public function updateMyCard(Request $request)
    {
        DB::beginTransaction();

        try {

            /* ================= GET LOGGED USER CARD ================= */

            $user = auth()->user();

            $card = StaffCard::with(['user', 'services', 'brochures', 'communityImages'])
                ->where('user_id', $user->id)
                ->firstOrFail();

            /* ================= VALIDATION ================= */

            $validator = Validator::make($request->all(), [

                'name'                      => 'required|string',
                'email'                     => 'nullable|email',

                'phone'                     => [
                    'nullable',
                    'string',
                    // Rule::unique('users', 'phone')->ignore($card->user_id),
                ],

                'password'                  => 'nullable|min:6',

                'designation'               => 'nullable|string',
                'website'                   => 'nullable|string',
                'company_name'              => 'nullable|string',
                'category'                  => 'nullable|string',
                'completion_score'          => 'nullable|integer|min:0|max:100',

                'company_email'             => [
                    'nullable',
                    'email',
                    // Rule::unique('users', 'email')->ignore($card->user_id),
                ],

                'linkedin'                  => 'nullable|string',
                'instagram'                 => 'nullable|string',
                'facebook'                  => 'nullable|string',
                'youtube'                   => 'nullable|string',

                'company_phone'             => 'nullable|string',
                'alternate_phone'           => 'nullable|string',
                'whatsapp'                  => 'nullable|string',
                'description'               => 'nullable|string',

                'profile_image'             => 'nullable|image',
                'cover_image'               => 'nullable|image',

                'services'                  => 'nullable|array',
                'services.*.heading'        => 'required|string',
                'services.*.items'          => 'nullable|array',

                'pincode'                   => 'nullable|string',
                'country'                   => 'nullable|string',
                'phone_code'                => 'nullable|string',
                'state'                     => 'nullable|string',
                'city'                      => 'nullable|string',
                'address'                   => 'nullable|string',
                'additional_phones'         => 'nullable|array',

                'community_images.*'        => 'nullable|image',
                'brochures.*'               => 'nullable|file|mimes:pdf',

                'delete_brochures'          => 'nullable|array',
                'delete_brochures.*'        => 'integer|exists:staff_brochures,id',

                'delete_community_images'   => 'nullable|array',
                'delete_community_images.*' => 'integer|exists:staff_community_images,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first(),
                ], 422);
            }

            if ($request->filled('delete_brochures')) {

                $brochures = $card->brochures()
                    ->whereIn('id', $request->delete_brochures)
                    ->get();

                foreach ($brochures as $brochure) {

                    Storage::disk('public')->delete($brochure->file_path);

                    $brochure->delete();
                }
            }

            if ($request->filled('delete_community_images')) {

                $images = $card->communityImages()
                    ->whereIn('id', $request->delete_community_images)
                    ->get();

                foreach ($images as $image) {

                    Storage::disk('public')->delete($image->image_path);

                    $image->delete();
                }
            }

            /* ================= UPDATE STAFF CARD ================= */

            $card->update([
                'name'              => $request->name,
                'designation'       => $request->designation,
                'phone'             => $request->phone,
                'email'             => $request->email,
                'website'           => $request->website,
                'company_name'      => $request->company_name,
                'company_email'     => $request->company_email,
                'linkedin'          => $request->linkedin,
                'instagram'         => $request->instagram,
                'facebook'          => $request->facebook,
                'youtube'           => $request->youtube,
                'category'          => $request->category,
                'completion_score'  => $request->completion_score,

                'company_phone'     => $request->company_phone,
                'alternate_phone'   => $request->alternate_phone,
                'whatsapp_number'   => $request->whatsapp,
                'bio'               => $request->description,

                'pincode'           => $request->pincode,
                'country'           => $request->country,
                'phone_code'        => $request->phone_code,
                'state'             => $request->state,
                'city'              => $request->city,
                'address'           => $request->address,

                'additional_phones' => $request->additional_phones
                    ? json_encode($request->additional_phones)
                    : null,
            ]);

            /* ================= UPDATE USER TABLE ================= */

            if ($card->user) {

                $userData = [
                    'name'  => $request->name,
                    'email' => $request->company_email,
                    'phone' => $request->phone,
                ];

                if ($request->filled('password')) {
                    $userData['password'] = bcrypt($request->password);
                }

                $card->user->update($userData);
            }

            /* ================= REMOVE PROFILE IMAGE ================= */
            if ($request->boolean('remove_profile_image')) {

                if ($card->profile_image && Storage::disk('public')->exists($card->profile_image)) {
                    Storage::disk('public')->delete($card->profile_image);
                }

                $card->profile_image = null;
            }

            /* ================= PROFILE IMAGE UPDATE ================= */

            if ($request->hasFile('profile_image')) {

                if ($card->profile_image && file_exists(public_path('storage/' . $card->profile_image))) {
                    unlink(public_path('storage/' . $card->profile_image));
                }

                $file = $request->file('profile_image');

                $filename = 'profile_' . Str::random(10) . '.webp';
                $destPath = public_path('storage/staff/profile/' . $filename);

                WebpService::convert(
                    $file->getPathname(),
                    $destPath,
                    75,
                    400,
                    400
                );

                $card->profile_image = 'staff/profile/' . $filename;
            }

            /* ================= REMOVE COVER IMAGE ================= */
            if ($request->boolean('remove_cover_image')) {

                if ($card->cover_image && Storage::disk('public')->exists($card->cover_image)) {
                    Storage::disk('public')->delete($card->cover_image);
                }

                $card->cover_image = null;
            }

            /* ================= COVER IMAGE UPDATE ================= */

            if ($request->hasFile('cover_image')) {

                if ($card->cover_image && file_exists(public_path('storage/' . $card->cover_image))) {
                    unlink(public_path('storage/' . $card->cover_image));
                }

                $file = $request->file('cover_image');

                $filename = 'cover_' . Str::random(10) . '.webp';
                $destPath = public_path('storage/staff/cover/' . $filename);

                WebpService::convert(
                    $file->getPathname(),
                    $destPath,
                    80,
                    1400,
                    600
                );

                $card->cover_image = 'staff/cover/' . $filename;
            }

            $card->save();

            /* ================= SYNC SERVICES ================= */

            // if ($request->has('services')) {

            //     $card->services()->delete();

            //     foreach ($request->services as $service) {
            //         $card->services()->create([
            //             'service_name' => $service,
            //         ]);
            //     }
            // }

            if ($request->services) {

                $card->services()->delete();

                foreach ($request->services as $service) {

                    $card->services()->create([
                        'service_details' => json_encode([
                            'heading' => $service['heading'] ?? '',
                            'items'   => $service['items'] ?? [],
                        ]),
                    ]);
                }
            }

            /* ================= COMMUNITY IMAGES ================= */

            if ($request->hasFile('community_images')) {

                foreach ($request->file('community_images') as $index => $file) {

                    $filename = 'community_' . Str::random(10) . '.webp';
                    $destPath = public_path('storage/staff/community/' . $filename);

                    WebpService::convert(
                        $file->getPathname(),
                        $destPath,
                        75,
                        300,
                        300
                    );

                    $card->communityImages()->create([
                        'image_path' => 'staff/community/' . $filename,
                        'name'       => $request->community_names[$index] ?? null,
                    ]);
                }
            }

            /* ================= BROCHURES ================= */

            if ($request->hasFile('brochures')) {

                foreach ($request->file('brochures') as $index => $file) {

                    $path = $file->store('staff/brochures', 'public');

                    $card->brochures()->create([
                        'file_path' => $path,
                        'name'      => $request->brochure_names[$index] ?? null,
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Staff card updated successfully',
                'data'    => $card->fresh(['user', 'services', 'brochures', 'communityImages']),
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
        $card = StaffCard::findOrFail($id);

        $card->services()->delete();
        $card->brochures()->delete();
        $card->communityImages()->delete();

        $card->delete();

        return response()->json([
            'success' => true,
            'message' => 'Staff card deleted successfully',
        ]);
    }

    public function dashboards()
    {
        $orgId = auth()->user()->organization_id;

        $total = DigitalCard::where('organization_id', $orgId)->count();

        $used = DigitalCard::where('organization_id', $orgId)
            ->whereNotNull('user_id')
            ->count();

        $available = DigitalCard::where('organization_id', $orgId)
            ->whereNull('user_id')
            ->count();

        $assignedCards = DigitalCard::with('user')
            ->where('organization_id', $orgId)
            ->whereNotNull('user_id')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'total'     => $total,
                'used'      => $used,
                'available' => $available,
                'assigned'  => $assignedCards,
            ],
        ]);
    }

    public function dashboard_w()
    {

        $orgId = auth()->user()->organization_id;

        /* ================= VALID ASSIGNED CARDS ================= */

        $assignedCards = DigitalCard::with(['user.staffCard'])
            ->where('organization_id', $orgId)
            ->where('status', 'active')
            ->whereDate('expires_at', '>=', now())
            ->whereNotNull('user_id')
            ->get();

        /* ================= VALID AVAILABLE CARDS ================= */

        $remainingSlots = DigitalCard::where('organization_id', $orgId)
            ->where('status', 'active')
            ->whereDate('expires_at', '>=', now())
            ->whereNull('user_id')
            ->count();

        /* ================= FORMAT FOR REACT ================= */

        $formattedAssigned = $assignedCards->map(function ($card) {

            $user  = $card->user;
            $staff = $user?->staffCard;

            return [
                'id'                => $user->id,
                'staff_card_id'     => $staff->id,

                'name'              => $user->name,
                'designation'       => $staff->designation ?? '',
                'email'             => $user->email ?? '',

                'expires_at'        => $card->expires_at
                    ? \Carbon\Carbon::parse($card->expires_at)->format('d M Y')
                    : null,
                'profile_image_url' => $staff && $staff->profile_image
                    ? asset('storage/' . $staff->profile_image)
                    : null,
                'organization_slug' => $user->organization->slug ?? null,
                'user_slug'         => $staff->slug ?? null,

            ];
        });

        return response()->json([
            'success' => true,
            'data'    => [
                'assigned'        => $formattedAssigned,
                'remaining_slots' => $remainingSlots,
            ],
        ]);
    }

    public function dashboard()
    {
        // dd("Sss");
        $orgId = auth()->user()->organization_id;

        /* ================= ACTIVE ASSIGNED CARDS ================= */

        $assignedCards = DigitalCard::with(['user.staffCard', 'user.organization'])
            ->where('organization_id', $orgId)
            ->where('status', 'active')
            ->whereDate('expires_at', '>=', now())
            ->whereNotNull('user_id')
            ->get();

        /* ================= EXPIRED CARDS ================= */

        $expiredCards = DigitalCard::with(['user.staffCard', 'user.organization'])
            ->where('organization_id', $orgId)
            ->where('status', 'active')
            ->whereDate('expires_at', '<', now())
            ->whereNotNull('user_id')
            ->get();

        /* ================= AVAILABLE CARDS ================= */

        $remainingSlots = DigitalCard::where('organization_id', $orgId)
            ->where('status', 'active')
            ->whereDate('expires_at', '>=', now())
            ->whereNull('user_id')
            ->count();

        /* ================= FORMAT ACTIVE ================= */

        $formattedAssigned = $assignedCards->map(function ($card) {

            $user  = $card->user;
            $staff = $user?->staffCard;

            return [
                'id'                => $user?->id,
                'staff_card_id'     => $staff?->id,

                'name'              => $user?->name,
                'designation'       => $staff?->designation ?? '',
                'email'             => $user?->email ?? '',

                'expires_at'        => $card->expires_at
                    ? \Carbon\Carbon::parse($card->expires_at)->format('d M Y')
                    : null,

                'profile_image_url' => $staff && $staff->profile_image
                    ? asset('storage/' . $staff->profile_image)
                    : null,

                'organization_slug' => $user?->organization?->slug,
                'user_slug'         => $staff?->slug,
                'completion_score'  => $staff?->completion_score ?? 0,

                'card_status'       => $user?->organization?->status,
            ];
        });

        /* ================= FORMAT EXPIRED ================= */

        $formattedExpired = $expiredCards->map(function ($card) {

            $user  = $card->user;
            $staff = $user?->staffCard;

            return [
                'id'                => $user?->id,
                'staff_card_id'     => $staff?->id,

                'name'              => $user?->name,
                'designation'       => $staff?->designation ?? '',
                'email'             => $user?->email ?? '',

                'expires_at'        => $card->expires_at
                    ? \Carbon\Carbon::parse($card->expires_at)->format('d M Y')
                    : null,

                'profile_image_url' => $staff && $staff->profile_image
                    ? asset('storage/' . $staff->profile_image)
                    : null,

                'organization_slug' => $user?->organization?->slug,
                'user_slug'         => $staff?->slug,

                'card_status'       => 'Expired',
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => [
                'assigned'        => $formattedAssigned,
                'expired'         => $formattedExpired,
                'remaining_slots' => $remainingSlots,
            ],
        ]);
    }

    public function show($id)
    {

        // dd("ssssss");
        $card = StaffCard::with([
            'brochures',
            'communityImages',
        ])
            ->where('slug', $id)
            ->first();

        if (! $card) {
            return response()->json([
                'success' => false,
                'message' => 'Card not found',
            ], 404);
        }

        $digitalCard = DigitalCard::where('user_id', $card->user_id)
            ->where('status', 'active')
            ->latest()
            ->first();

        if (! $digitalCard) {
            return response()->json([
                'success' => false,
                'message' => 'No active digital card found.',
            ], 403);
        }

        if (now()->gt($digitalCard->expires_at)) {
            return response()->json([
                'success'    => false,
                'message'    => 'This digital card has expired.',
                'expired'    => true,
                'expires_at' => $digitalCard->expires_at,
            ], 403);
        }

        $brand = Brand::where(
            'organization_id',
            $card->organization_id
        )->first();
        // ✅ Fetch Organization Email
        $organization = Organization::where('id', $card->organization_id)->first();

        $exists = ProfileAnalytics::where('card_id', $card->id)
            ->where('action', 'view')
            ->where('ip_address', request()->ip())
            ->whereDate('created_at', today())
            ->exists();

        if (! $exists) {
            ProfileAnalytics::create([
                'card_id'    => $card->id,
                'user_id'    => $card->user_id,
                'action'     => 'view',
                'ip_address' => request()->ip(),
            ]);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'id'                 => $card->id,
                'name'               => $card->name,
                'user_id'            => $card->user_id,
                'designation'        => $card->designation,
                'phone'              => $card->phone,
                'email'              => $card->email,
                'website'            => $card->website,
                'company_name'       => $card->company_name,
                'company_email'      => $card->company_email,
                'category'           => $card->category,
                'completion_score'   => $card->completion_score,
                'linkedin'           => $card->linkedin,
                'instagram'          => $card->instagram,
                'facebook'           => $card->facebook,
                'youtube'            => $card->youtube,

                'organization_email' => $organization ? $organization->email : null,

                'company_phone'      => $card->company_phone,
                'WhatsApp'           => $card->whatsapp_number,
                'description'        => $card->bio,
                'alternate_phone'    => $card->alternate_phone,

                'pincode'            => $card->pincode,
                'country'            => $card->country,
                'phone_code'         => $card->phone_code,
                'state'              => $card->state,
                'city'               => $card->city,
                'address'            => $card->address,
                'additional_phones'  => $card->additional_phones,

                'branding_logo'      => $brand && $brand->logo
                    ? asset('storage/' . $brand->logo)
                    : null,
                'brand_name'         => $brand ? $brand->brand_name : null,

                'services'           => $card->services,

                'profile_image_url'  => $card->profile_image
                    ? asset('storage/' . $card->profile_image)
                    : null,

                'cover_image_url'    => $card->cover_image
                    ? asset('storage/' . $card->cover_image)
                    : null,

                // 'brochures'         => $card->brochures->map(function ($b) {
                //     return [
                //         'id'   => $b->id,
                //         'name' => $b->name,
                //         'url'  => asset('storage/' . $b->file_path),
                //     ];
                // }),

                'brochures'          => $card->brochures->map(function ($b) {
                    $filePath    = storage_path('app/public/' . $b->file_path);
                    $sizeInBytes = file_exists($filePath) ? filesize($filePath) : null;

                    return [
                        'id'         => $b->id,
                        'name'       => $b->name,
                        'url'        => asset('storage/' . $b->file_path),
                        'size'       => $sizeInBytes, // in bytes
                        'size_bytes' => $sizeInBytes, // for better frontend compatibility
                        'file_size'  => $sizeInBytes, // extra fallback
                    ];
                }),

                'community_images'   => $card->communityImages->map(function ($c) {
                    return [
                        'id'   => $c->id,
                        'name' => $c->name,
                        'url'  => asset('storage/' . $c->image_path),
                    ];
                }),

                'card_status'        => $digitalCard->status,
                'issued_at'          => $digitalCard->issued_at,
                'expires_at'         => $digitalCard->expires_at,
            ],
        ]);
    }

    public function activateCard($userId)
    {
        DB::beginTransaction();

        try {

            $user = User::findOrFail($userId);

            $organizationId = $user->organization_id;

            // Check if user already has an active card
            $activeCard = DigitalCard::where('user_id', $user->id)
                ->where('organization_id', $organizationId)
                ->whereDate('expires_at', '>=', now())
                ->exists();

            if ($activeCard) {
                return response()->json([
                    'success' => false,
                    'message' => 'User already has an active card.',
                ], 422);
            }

            // Remove user from old expired cards (optional)
            DigitalCard::where('user_id', $user->id)
                ->where('organization_id', $organizationId)
                ->whereDate('expires_at', '<', now())
                ->update([
                    'user_id' => null,
                ]);

            // Find available card
            $availableCard = DigitalCard::where(
                'organization_id',
                $organizationId
            )
                ->whereNull('user_id')
                ->where('status', 'active')
                ->whereDate('expires_at', '>=', now())
                ->first();

            if (! $availableCard) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'No available cards found for this organization.',
                ], 422);
            }

            // Assign card
            $availableCard->update([
                'user_id'    => $user->id,
                'issued_at'  => now(),
                'expires_at' => now()->addDays(90), // or validity days
            ]);

            Mail::to($user->email)->send(
                new CardRenwalMail(
                    $user,
                    $availableCard
                )
            );

            try {

                \Log::error('Card Activation WhatsApp Failed', [
                    'user_details' => $user->toArray(),

                ]);

                $messenger = app(\App\Services\Messenger360Service::class);

                if (! empty($user->phone)) {

                    $profileLink = env('FRONTEND_URL') . '/login';

                    $message = "Hi {$user->name} ✨

Your Mi Profile access has been activated successfully.

Your digital identity is now active and ready to use.

Card Number:
{$availableCard->card_number}

Activation Date:
" . now()->format('d M Y') . "

Expiry Date:
" . \Carbon\Carbon::parse($availableCard->expires_at)->format('d M Y') . "

Access your profile:
{$profileLink}

Team Mi Profile
Connect Beyond Contacts.";

                    $messenger->send(
                        (string) $user->phone,
                        $message
                    );
                }

            } catch (\Exception $e) {

                \Log::error('Card Activation WhatsApp Failed', [
                    'user_id' => $user->id,
                    'message' => $e->getMessage(),
                    'line'    => $e->getLine(),
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Card activated successfully.',
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function mystaffcard()
    {
       

        $user = auth()->user();

        $card = StaffCard::with(['brochures', 'communityImages', 'services', 'organization'])
            ->where('user_id', $user->id)
            ->first();

        if (! $card) {
            return response()->json([
                'success' => false,
                'message' => 'No staff card found',
            ], 404);
        }

        // 🔥 Fetch brand settings
        $brand = Brand::where('organization_id', $card->organization_id)->first();

        // dd($brand->brand_name);
        return response()->json([
            'success' => true,
            'data'    => [
                'id'                => $card->id,
                'name'              => $card->name,
                'user_slug'         => $card->slug,
                'user_id'           => $user->id,
                'designation'       => $card->designation,
                'phone'             => $card->phone,
                'email'             => $card->email,
                'category'          => $card->category,
                'completion_score'  => $card->completion_score,
                // 'score'             => $card->score,
                'website'           => $card->website,
                // 'company_name'      => $card->company_name,
                'company_name'      => $brand->brand_name,
                'company_email'     => $card->company_email,
                'linkedin'          => $card->linkedin,
                'instagram'         => $card->instagram,
                'facebook'          => $card->facebook,
                'youtube'           => $card->youtube,
                'organization_slug' => $card->organization?->slug,

                'services'          => $card->services,

                'company_phone'     => $card->company_phone,
                // 'company_phone'     => $brand->brand_name,
                'bio'               => $card->bio,
                'alternate_phone'   => $card->alternate_phone,
                'whatsapp'          => $card->whatsapp_number,

                'pincode'           => $card->pincode,
                'country'           => $card->country,
                'phone_code'        => $card->phone_code,
                'state'             => $card->state,
                'city'              => $card->city,
                'address'           => $card->address,
                'additional_phones' => $card->additional_phones,

                'profile_image_url' => $card->profile_image
                    ? asset('storage/' . $card->profile_image)
                    : null,

                'cover_image_url'   => $card->cover_image
                    ? asset('storage/' . $card->cover_image)
                    : null,

                'brochures'         => $card->brochures->map(function ($b) {
                    return [
                        'id'   => $b->id,
                        'name' => $b->name,
                        'url'  => asset('storage/' . $b->file_path),
                    ];
                }),

                'community_images'  => $card->communityImages->map(function ($c) {
                    return [
                        'id'   => $c->id,
                        'name' => $c->name,
                        'url'  => asset('storage/' . $c->image_path),
                    ];
                }),

                // ✅ ADD PERMISSIONS HERE
                'permissions'       => [
                    'cover_change'          => $brand->cover_change ?? 0,
                    'custom_community_logo' => $brand->custom_community_logo ?? 0,
                ],
            ],
        ]);
    }

    public function deactivateCard($userId)
    {
        DB::beginTransaction();

        try {

            $user = User::findOrFail($userId);

            $card = DigitalCard::where('user_id', $user->id)
                ->where('organization_id', $user->organization_id)
                ->where('status', 'active')
                ->first();

            if (! $card) {
                return response()->json([
                    'success' => false,
                    'message' => 'No assigned card found.',
                ], 404);
            }

            $card->update([
                'user_id' => null,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Card deactivated successfully.',
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function deleteCard_old($userId)
    {

        DB::beginTransaction();

        try {

            $user = User::findOrFail($userId);

            // Find assigned digital card
            $digitalCard = DigitalCard::where('user_id', $user->id)
                ->where('organization_id', $user->organization_id)
                ->first();

            if ($digitalCard) {

                $digitalCard->update([
                    'user_id' => null,
                    // 'issued_at' => null,
                ]);

            }

            // Delete staff card
            StaffCard::where('user_id', $user->id)
                ->forceDelete();

            // Delete user
            $user->forceDelete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Card deleted successfully.',
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function deleteCard($userId)
    {
        DB::beginTransaction();

        try {
            $user = User::findOrFail($userId);

            // Get organization employer (to notify)
            $employer = User::where('organization_id', $user->organization_id)
                ->where('role', 'employeer')
                ->first();

            // Find assigned digital card
            $digitalCard = DigitalCard::where('user_id', $user->id)
                ->where('organization_id', $user->organization_id)
                ->first();

            if ($digitalCard) {
                $digitalCard->update([
                    'user_id' => null,
                ]);
            }

            // Delete staff card
            StaffCard::where('user_id', $user->id)->forceDelete();

            // Delete user
            $user->forceDelete();

            DB::commit();

            // Send notification email to Organization Employer
            if ($employer) {
                try {
                    $details = [
                        'employer_name'      => $employer->name,
                        'deleted_user_name'  => $user->name,
                        'deleted_user_email' => $user->email,
                        'organization_name'  => $user->organization->name ?? 'Your Organization',
                    ];

                    Mail::to($employer->email)->send(new \App\Mail\CardDeletedMail($details));
                    // Mail::to('sk.asif0490@gmail.com')->send(new \App\Mail\CardDeletedMail($details));
                } catch (\Exception $mailEx) {
                    \Log::error('Failed to send card deletion email: ' . $mailEx->getMessage());
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Card deleted successfully.',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
