<?php
namespace App\Http\Controllers;

use App\Models\WhatsAppUnsubscribe;
use Illuminate\Http\Request;

class WhatsAppController extends Controller
{
    public function unsubscribe(Request $request)
    {
        $phone = $request->query('phone');

        if (empty($phone)) {
            return response()->json([
                'success' => false,
                'message' => 'Phone number is required',
            ], 400);
        }

        // Clean phone number
        $cleanPhone = preg_replace('/\D/', '', $phone);

        // Save unsubscribe record
        WhatsAppUnsubscribe::updateOrCreate(
            ['phone' => $cleanPhone],
            [
                'is_active'       => true,
                'unsubscribed_at' => now(),
                'reason'          => 'User clicked unsubscribe link via WhatsApp',
            ]
        );

        return view('whatsapp.unsubscribed', [
            'phone' => $cleanPhone,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'You have been successfully unsubscribed from WhatsApp messages.',
            'phone'   => $cleanPhone,
        ], 200);
    }
}
