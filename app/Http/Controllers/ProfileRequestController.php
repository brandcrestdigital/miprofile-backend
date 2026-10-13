<?php
namespace App\Http\Controllers;

use App\Mail\ProfileRequestMail;
use App\Models\ProfileRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class ProfileRequestController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'full_name'         => 'required|string|max:255',
            'phone_number'      => 'required|digits:10',
            'email'             => 'required|email',
            'company_name'      => 'required|string|max:255',
            'profiles_required' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $profileRequest = ProfileRequest::create([
            'full_name'         => $request->full_name,
            'phone_number'      => $request->phone_number,
            'email'             => $request->email,
            'company_name'      => $request->company_name,
            'profiles_required' => $request->profiles_required,
        ]);

        $emails = explode(',', env('PROFILE_REQUEST_MAILS'));

        Mail::to($emails)
            ->send(new ProfileRequestMail($profileRequest));

        return response()->json([
            'status'  => true,
            'message' => 'Request submitted successfully',
            'data'    => $profileRequest,
        ]);
    }
}
