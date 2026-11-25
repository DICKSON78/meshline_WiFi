<?php

namespace App\Http\Controllers;

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Public website API — contact messages and homepage stats only
 * (routes/api/public.php).
 */
class PublicController extends Controller
{
    public function submitContactMessage(Request $request): JsonResponse
    {
        try {
            $name = $request->input('name');
            $email = $request->input('email');
            $message = $request->input('message');

            if (! $name || ! $email || ! $message) {
                return ApiResponse::fail('Please fill in all required fields', 400);
            }

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ApiResponse::fail('Invalid email address. Please enter a valid email.', 400);
            }

            DB::table('contact_messages')->insert([
                'name' => $name,
                'email' => $email,
                'subject' => $request->input('subject') ?: null,
                'phone' => $request->input('phone') ?: null,
                'message' => $message,
                'status' => 'unread',
                'created_at' => now(),
            ]);

            return ApiResponse::success([], 'Thank you for your message! We will get back to you shortly.');
        } catch (\Throwable $e) {
            report($e);

            return ApiResponse::fail('Samahani, kuna tatizo limetokea. Tafadhali jaribu tena baadaye.', 500);
        }
    }

    public function getPublicStats(): JsonResponse
    {
        try {
            $users = (int) (DB::table('users')->where('role', 'customer')->count() ?? 0);
            $locations = (int) (DB::table('locations')->where('status', '!=', 'inactive')->count() ?? 0);
            $revenue = (float) (DB::table('payments')->where('status', 'completed')->sum('amount') ?? 0);
            $routers = (int) (DB::table('mikrotiks')->where('status', 'online')->count() ?? 0);

            return ApiResponse::json([
                'success' => true,
                'stats' => [
                    'users' => $users > 0 ? ((int) round($users / 1000)).'K+' : '10K+',
                    'locations' => $locations > 0 ? $locations.'+' : '50+',
                    'data_gb' => $revenue > 0 ? ((int) round($revenue / 1000)).'K+' : '100K+',
                    'uptime' => $routers > 0 ? '99%' : '99%',
                ],
            ]);
        } catch (\Throwable $e) {
            // Homepage stats must never fail: keep the legacy hard-coded values.
            return ApiResponse::json([
                'success' => true,
                'stats' => ['users' => '10K+', 'locations' => '50+', 'data_gb' => '100K+', 'uptime' => '99%'],
            ]);
        }
    }
}
