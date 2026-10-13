<?php

use App\Http\Controllers\AdminAffiliateController;
use App\Http\Controllers\AffiliateController;
use App\Http\Controllers\AffiliateWalletController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Auth\EmployeeAuthController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\BrandingController;
use App\Http\Controllers\BusinessCardController;
use App\Http\Controllers\CardPricingSettingController;
use App\Http\Controllers\CouponController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DigitalCardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\GoogleContactController;
use App\Http\Controllers\InvoiceGenerateController;
use App\Http\Controllers\MeetingController;
use App\Http\Controllers\OrganizationCardPurchaseController;
use App\Http\Controllers\OrginazationController;
use App\Http\Controllers\PaymentTransactionController;
use App\Http\Controllers\ProfileAnalyticsController;
use App\Http\Controllers\ProfileRequestController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\StaffCardController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\WhatsAppController;
use App\Http\Controllers\CashfreeController;

use App\Services\Messenger360Service;
use Illuminate\Support\Facades\Route;


Route::post('add-company', [AdminAffiliateController::class, 'store']);

Route::get('/mail-test', function () {
    try {
        Mail::raw('Mail Working', function ($message) {
            $message->to('sk.asif0490@gmail.com')
                ->subject('SMTP Test');
        });

        return 'Mail Sent Successfully';
    } catch (\Exception $e) {
        return $e->getMessage();
    }
});

Route::get('/test-whatsapp/{phone}', function (Messenger360Service $whatsapp, $phone) {

    $text = 'Hello 👋 This is a test message from BCD.';

    try {
        $response = $whatsapp->send($phone, $text);

        return response()->json([
            'success' => true,
            'data'    => $response,
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ], 500);
    }
});

//  download apk

Route::get('/download-apk-admin', function () {

    $filePath = public_path('apk/miEmp.apk');

    if (!file_exists($filePath)) {
        return response()->json([
            'status' => false,
            'message' => 'APK file not found'
        ], 404);
    }

    return response()->download(
        $filePath,
        'miEmp.apk',
        [
            'Content-Type' => 'application/vnd.android.package-archive',
        ]
    );
});



Route::get('/download-apk-org', function () {

    $filePath = public_path('apk/miuser.apk');

    if (!file_exists($filePath)) {
        return response()->json([
            'status' => false,
            'message' => 'APK file not found'
        ], 404);
    }

    return response()->download(
        $filePath,
        'miEmp.apk',
        [
            'Content-Type' => 'application/vnd.android.package-archive',
        ]
    );
});


// end download apk

Route::get('/whatsapp/unsubscribe', [WhatsAppController::class, 'unsubscribe'])
    ->name('whatsapp.unsubscribe');

Route::get(
    '/organization/download-invoice/{purchase}',
    [InvoiceGenerateController::class, 'downloadInvoice']
);

Route::post('/profile-analytics', [ProfileAnalyticsController::class, 'store']);

Route::post('profile-request', [ProfileRequestController::class, 'store']);

Route::post('/scan-business-card', [BusinessCardController::class, 'scan']);
Route::post('/save-google-contact', [GoogleContactController::class, 'save']);

Route::get('/meeting/cancel/{id}', [MeetingController::class, 'getMeetingForCancel']);
Route::post('/meeting/cancel/{token}', [MeetingController::class, 'cancelByGuest']);
Route::post('/report-profile', [ReportController::class, 'store']);

Route::prefix('/public')->group(function () {

    // ================= SETTINGS =================
    Route::get('/settings/brand/', [BrandingController::class, 'show']);

    Route::get('/settings/card-pricing/', [CardPricingSettingController::class, 'index']);
    Route::get('/organization/{slug}', [OrginazationController::class, 'showBySlug']);
    Route::post('/meeting-request', [MeetingController::class, 'store']);
    Route::get('/meeting-cancelled-by-client/{id}', [MeetingController::class, 'cancelByHost']);
});

Route::get('/orginazation-dashboard/staff-cards/{id}', [StaffCardController::class, 'show']);

Route::prefix('auth')->group(function () {

    // ================= AUTH =================

    Route::get('check-ref', [AuthController::class, 'checkReferral']);

    Route::post('admin-register', [AuthController::class, 'admin_register']);
    Route::post('register-org', [AuthController::class, 'publicStore']);


    Route::post('admin-login', [AuthController::class, 'admin_login']);
    Route::post('user-register', [AuthController::class, 'register']);
    Route::post('user-login', [AuthController::class, 'login']);
    Route::post('employee-register', [EmployeeAuthController::class, 'register']);
    Route::post('employee-login', [EmployeeAuthController::class, 'login']);

    // Login with Orginazation
    Route::post('org-login', [AuthController::class, 'org_login']);

    // ================= PASSWORD / OTP =================
    Route::post('forgot-password', [AuthController::class, 'sendOtp']);
    Route::post('verify-otp', [AuthController::class, 'verifyOtp']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);

    Route::get('app-logo-settings', [SettingController::class, 'show']);

    Route::post('organization/forgot-password', [AuthController::class, 'OrgsendOtp']);

    // ================= GOOGLE OAUTH =================
    Route::get('google/redirect', [AuthController::class, 'redirectToGoogle']);
    Route::get('google/callback', [AuthController::class, 'handleGoogleCallback']);
});

Route::get('/payment/cashfree/return', [CashfreeController::class, 'paymentReturn']);

Route::prefix('orginazation-dashboard')->middleware(['api', 'jwt.auth'])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Orginazation Purchase APIs
    |--------------------------------------------------------------------------
    */
    Route::post('/add-organization', [OrginazationController::class, 'store']);
    Route::get('/organizations', [OrginazationController::class, 'index']);
    Route::get('/my-orginazation', [OrganizationCardPurchaseController::class, 'show_only']);
    Route::get('/organization/{id}', [OrginazationController::class, 'show']);
    Route::post('/organization/{id}', [OrginazationController::class, 'update']);
    Route::post('/organizations/{id}/cashfree/create-order', [CashfreeController::class, 'createOrder']);

    Route::delete('/organization/{id}', [OrginazationController::class, 'destroy']);

    Route::post('/update-my-organization', [OrginazationController::class, 'updateMyOrg']);

    Route::get('/get-my-organization', [OrginazationController::class, 'showMyOrg']);

    Route::get('/profile-performance', [ProfileAnalyticsController::class, 'profilePerformance']);
    Route::get('/profile-performance/{cardId}', [ProfileAnalyticsController::class, 'performanceChart']);
    Route::get('/recent-activity', [ProfileAnalyticsController::class, 'recentActivity']);



    /*
    |--------------------------------------------------------------------------
    | Card Purchase APIs
    |--------------------------------------------------------------------------
    */

    Route::post('/card-purchase', [OrganizationCardPurchaseController::class, 'store']);
    Route::get('/card-purchases/{organization_id}', [OrganizationCardPurchaseController::class, 'index']);
    Route::get('/card-purchase/{id}', [OrganizationCardPurchaseController::class, 'show']);
    Route::delete('/card-purchase/{id}', [OrganizationCardPurchaseController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | STAFF
    |--------------------------------------------------------------------------
    */

    Route::get('/list-staff', [EmployeeController::class, 'index']);
    Route::post('/staff/{id}', [EmployeeController::class, 'update']);

    /*
    |--------------------------------------------------------------------------
    | NFC CARD START
    |--------------------------------------------------------------------------
    */

    Route::get('/nfc-requests', [OrganizationCardPurchaseController::class, 'nfcRequests']);
    Route::get('/admin-nfc-requests', [OrganizationCardPurchaseController::class, 'nfcAdminRequests']);
    // Route::post('/nfc-requests', [OrganizationCardPurchaseController::class, 'store']);
    // Route::get('/organization/nfc-requests/{id}', [OrganizationCardPurchaseController::class, 'show']);
    Route::put('/admin-nfc-requests/{id}', [OrganizationCardPurchaseController::class, 'updateAdminNfcRequest']);
    Route::post('/reapply-nfc-request/{id}', [OrganizationCardPurchaseController::class, 'reapplyNfcRequest']);
    Route::post('/update-nfc-requests/{id}', [OrganizationCardPurchaseController::class, 'nfcAddDetails']);

    /*
    |--------------------------------------------------------------------------
    | NFC CARD  CLOSE
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | Digital Card APIs
    |--------------------------------------------------------------------------
    */

    Route::post('/digital-card', [DigitalCardController::class, 'store']);
    Route::get('/digital-cards/{organization_id}', [DigitalCardController::class, 'index']);
    Route::delete('/digital-card/{id}', [DigitalCardController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | Restore
    |--------------------------------------------------------------------------
    */

    Route::post('/organization/{id}/restore', [OrginazationController::class, 'restore']);
    Route::post('/card-purchase/{id}/restore', [OrganizationCardPurchaseController::class, 'restore']);
    Route::post('/digital-card/{id}/restore', [DigitalCardController::class, 'restore']);

    // ================= ADMIN SETTING CARD AMOUNT FIXING =================

    Route::get('/card-pricing', [CardPricingSettingController::class, 'index']);
    Route::post('/card-pricing', [CardPricingSettingController::class, 'save']);
    Route::delete('/card-pricing', [CardPricingSettingController::class, 'destroy']);

    Route::get(
        '/branding',
        [BrandingController::class, 'show']
    );

    // Create or update branding (logo + favicon + name)
    Route::post(
        '/branding',
        [BrandingController::class, 'store']
    );

    // purchasing the cards
    Route::post(
        '/organizations/{organization}/purchase-cards',
        [OrganizationCardPurchaseController::class, 'purchase']
    );

    // list of cards purchase

    Route::post(
        '/organization/send-invoice/{purchase}',
        [InvoiceGenerateController::class, 'sendInvoice']
    );

    Route::get(
        '/organization/download-invoice/{purchase}',
        [InvoiceGenerateController::class, 'downloadInvoice']
    );

    Route::get('/organization/{id}', [OrganizationCardPurchaseController::class, 'show']);
    Route::get('/organization-stats/{id}', [OrganizationCardPurchaseController::class, 'stats']);

    // orginazation feature get and update routes

    // Get single organization (with features)
    Route::get('/organization-settings/{id}', [OrginazationController::class, 'show_access']);

    // Update organization features
    Route::put('/organization/{id}/features', [OrginazationController::class, 'updateFeatures']);
    Route::get('/payment-transactions', [PaymentTransactionController::class, 'index']);
    Route::get(
        '/dashboard',
        [DashboardController::class, 'index']
    );

    // OrganizationCardPurchaseController

    // ================= AUTH =================
    Route::get('/profile', [AuthController::class, 'profile']);

    //===================== ORGINAZATION =====================

    Route::post('/brand/update', [BrandController::class, 'update']);
    Route::get('/brand', [BrandController::class, 'show']);

    // staff cards adding
    Route::post('/staff-cards', [StaffCardController::class, 'store']);
    Route::get('/staff-cards', [StaffCardController::class, 'index']);
    Route::post('/update-staff-cards/{id}', [StaffCardController::class, 'update']);
    Route::get('/staff-cardss', [StaffCardController::class, 'dashboard']);
    // Route::get('/staff-cards/{id}', [StaffCardController::class, 'show']);

    Route::get('/payment-history', [PaymentTransactionController::class, 'paymentHistory']);
    Route::get('/', [OrginazationController::class, 'dashboard']);
    Route::get('/my-staff-card', [StaffCardController::class, 'mystaffcard']);
    Route::post('/update-my-staff-card', [StaffCardController::class, 'updateMyCard']);
    Route::get('/my-meetings', [MeetingController::class, 'myMeetings']);
    Route::post('/meetings/{id}/confirm', [MeetingController::class, 'confirm']);
    Route::get('/meetings/{id}/download-ics', [MeetingController::class, 'downloadIcs']);
    Route::post('/meeting-cancelled-by-host/{id}', [MeetingController::class, 'cancelByHost']);

    Route::delete('/meetings/{id}', [MeetingController::class, 'destroy']);

    Route::apiResource('coupons', CouponController::class);
    Route::post('/apply-coupon', [CouponController::class, 'applyCoupon']);

    Route::get('/tickets', [TicketController::class, 'allTickets']);

    Route::post(
        '/tickets/{id}/update',
        [TicketController::class, 'updateTicket']
    );
});

Route::prefix('employee')->middleware(['api', 'auth:employee'])->group(function () {
    Route::post('/add-organization', [OrginazationController::class, 'store']);
    Route::get('/organizations', [OrginazationController::class, 'index_logined']);
    Route::put('/update-organization/{id}', [OrginazationController::class, 'update_org_employee']);
    Route::get('/dashboard', [EmployeeController::class, 'dashboard_index']);

    Route::put('/profile', [EmployeeController::class, 'profile_update']);
    Route::get('/profile', [EmployeeController::class, 'show']);
});

Route::prefix('ecom')->group(function () {

    // app settion globel
    Route::get('/app-logo-settings', [SettingController::class, 'show']);
});

Route::prefix('user-dashboard')->middleware(['api', 'jwt.auth'])->group(function () {

    // Cart Functionalities

    // profile update
    Route::post('/update-profile', [AuthController::class, 'update']);

    Route::post(
        '/staff-cards/{user}/activate',
        [StaffCardController::class, 'activateCard']
    );

    Route::delete(
        '/staff-cards/{user}/delete-card',
        [StaffCardController::class, 'deleteCard']
    );

    // Not working
    Route::post(
        '/staff-cards/{user}/deactivate',
        [StaffCardController::class, 'deactivateCard']
    );

    // whishlist toggle

    Route::post('/tickets', [TicketController::class, 'store']);

    Route::get('/tickets', [TicketController::class, 'index']);

    Route::get('/tickets/{id}', [TicketController::class, 'show']);
});

// Affilite


// Route::prefix('affiliate')->group(function () {
//     Route::post('/register', [AffiliateController::class, 'register']);
//     Route::post('/login', [AffiliateController::class, 'login']);
// });



Route::prefix('admin/affiliates')->middleware(['api', 'jwt.auth'])->group(function () {

    // Affiliate list
    Route::get('/', [AdminAffiliateController::class, 'index']);
    Route::get('/{id}', [AdminAffiliateController::class, 'show']);
    Route::post('/{id}/approve', [AdminAffiliateController::class, 'approve']);
    Route::post('/{id}/reject', [AdminAffiliateController::class, 'reject']);
    Route::put('/{id}', [AdminAffiliateController::class, 'update']);
    Route::post('/{id}/toggle-status', [AdminAffiliateController::class, 'toggleStatus']);
    Route::get('/{id}/remark', [AdminAffiliateController::class, 'remark']);

    Route::get('/{id}/transactions', [AdminAffiliateController::class, 'transactions']);

    Route::post('/withdrawals/{withdrawal}/approve', [AdminAffiliateController::class, 'approveWithdrawal']);
});





Route::prefix('affiliate')->group(function () {

    Route::post('/register', [AffiliateController::class, 'register']);

    Route::post('/login', [AffiliateController::class, 'login']);

    Route::middleware('affiliate.jwt')->group(function () {

        Route::get('/dashboard', [AffiliateController::class, 'dashboard']);
        Route::get('/organizations', [AffiliateController::class, 'organizations']);
        Route::get('/wallet', [AffiliateController::class, 'wallet']);
        Route::get('/transactions', [AffiliateController::class, 'transactions']);
        Route::post('/withdraw-request', [AffiliateController::class, 'withdrawRequest']);

        Route::put('/profile', [AffiliateController::class, 'updateProfile']);
        Route::post('/bank-details', [AffiliateController::class, 'submitBankDetails']);
        Route::get('/bank-details', [AffiliateController::class, 'getBankDetails']);


        Route::post('/payment/cashfree/create-order', [CashfreeController::class, 'createOrder']);
    });
});
