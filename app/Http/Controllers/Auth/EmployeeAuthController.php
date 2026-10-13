<?php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\EmployeeWelcomeMail;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

class EmployeeAuthController extends Controller
{
    public function login(Request $request)
    {
        // Validation
        $validator = Validator::make($request->all(), [
            'email'    => 'required|email',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation Error',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // $employee = Employee::where('email', $request->email)->first();

        // if (! $employee) {
        //     dd('Employee not found');
        // }

        // dd([
        //     'password_matches' => Hash::check($request->password, $employee->password),
        //     'stored_hash'      => $employee->password,
        // ]);

        $credentials = $request->only('email', 'password');

        try {
            // Correct way - No guard() here for attempt()
            // if (! $token = JWTAuth::attempt($credentials)) {
            //     return response()->json([
            //         'success' => false,
            //         'error'   => 'Invalid credentials',
            //     ], 401);
            // }

            if (! $token = auth('employee')->attempt($credentials)) {
                return response()->json([
                    'success' => false,
                    'error'   => 'Invalid credentials',
                ], 401);
            }

        } catch (JWTException $e) {
            return response()->json([
                'success' => false,
                'error'   => 'Could not create token',
            ], 500);
        }

        // Get the authenticated employee
        $employee = auth('employee')->user();

        if (! $employee->is_active) {
            auth('employee')->logout();
            return response()->json([
                'success' => false,
                'error'   => 'Account is inactive',
            ], 403);
        }

        return response()->json([
            'success'  => true,
            'token'    => $token,
            'employee' => $employee,
        ]);
    }
    public function me()
    {
        return response()->json(JWTAuth::guard('employee')->user());
    }

    public function logout()
    {
        JWTAuth::guard('employee')->logout();
        return response()->json(['success' => true, 'message' => 'Successfully logged out']);
    }

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:255',
            'email'       => 'required|string|email|max:255|unique:employees',
            // 'password'    => 'required|string|min:6',
            'phone'       => 'required|string|min:10|max:13',
            'designation' => 'nullable|string|max:100',
            'department'  => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Generate random password
        $randomPassword = \Str::random(10);

        $employee = Employee::create([
            'name'      => $request->name,
            'email'     => $request->email,
            'password'  => Hash::make($randomPassword),
            'phone'     => $request->phone,
                                 // 'designation' => $request->designation,
                                 // 'department'  => $request->department,
            'is_active' => true, // You can change this to false for admin approval
        ]);

        try {
            Mail::to($employee->email)->send(new EmployeeWelcomeMail($employee, $randomPassword));
        } catch (\Exception $e) {
            // Don't fail registration if email fails
            \Log::error('Employee welcome email failed: ' . $e->getMessage());
        }
                                                     // Auto login after registration (optional)
        $token = auth('employee')->login($employee); // ← Correct way

        return response()->json([
            'success'  => true,
            'message'  => 'Employee registered successfully',
            'token'    => $token,
            'employee' => $employee,
        ], 201);
    }

}