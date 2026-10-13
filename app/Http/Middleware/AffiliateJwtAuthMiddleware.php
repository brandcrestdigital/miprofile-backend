<?php

namespace App\Http\Middleware;




use Closure;
use Exception;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Exceptions\TokenBlacklistedException;

class AffiliateJwtAuthMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {

            $affiliate = auth('affiliate')->user();

            if (!$affiliate) {
                return response()->json([
                    'success' => false,
                    'error'   => 'AFFILIATE_NOT_FOUND',
                    'message' => 'Affiliate account not found.',
                ], 401);
            }

            if ($affiliate->status !== 'approved') {
                return response()->json([
                    'success' => false,
                    'error'   => 'AFFILIATE_NOT_APPROVED',
                    'message' => 'Your affiliate account is not approved.',
                ], 403);
            }

            if (!$affiliate->is_active) {
                return response()->json([
                    'success' => false,
                    'error'   => 'AFFILIATE_INACTIVE',
                    'message' => 'Your affiliate account is inactive.',
                ], 403);
            }
        } catch (TokenExpiredException $e) {

            return response()->json([
                'success' => false,
                'error'   => 'TOKEN_EXPIRED',
                'message' => 'Your session has expired. Please login again.',
            ], 401);
        } catch (TokenInvalidException $e) {

            return response()->json([
                'success' => false,
                'error'   => 'TOKEN_INVALID',
                'message' => 'Invalid authentication token.',
            ], 401);
        } catch (TokenBlacklistedException $e) {

            return response()->json([
                'success' => false,
                'error'   => 'TOKEN_BLACKLISTED',
                'message' => 'Token has been revoked. Please login again.',
            ], 401);
        } catch (Exception $e) {

            return response()->json([
                'success' => false,
                'error'   => 'TOKEN_MISSING',
                'message' => 'Authorization token not provided.',
            ], 401);
        }

        return $next($request);
    }
}
