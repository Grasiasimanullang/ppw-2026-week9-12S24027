<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthTokenController extends Controller
{
    use ApiResponse;

    /** POST /api/v1/auth/login */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email'       => 'required|email',
            'password'    => 'required|string',
            'device_name' => 'nullable|string|max:100',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return $this->problemResponse(
                title: 'Invalid Credentials',
                detail: 'Kombinasi email atau password yang diberikan tidak cocok.',
                status: 401,
                errorSlug: 'invalid-credentials'
            );
        }

        $token = $user->createToken($validated['device_name'] ?? 'postman-client')->plainTextToken;

        return $this->successResponse(
            data: [
                'token_type'   => 'Bearer',
                'access_token' => $token,
                'user' => [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                ],
            ],
            message: 'Otentikasi API berhasil. Lampirkan access_token pada header Authorization: Bearer <token>.'
        );
    }

    /** GET /api/v1/auth/me */
    public function me(Request $request): JsonResponse
    {
        return $this->successResponse(
            data: $request->user(),
            message: 'Profil pemilik token aktif berhasil diambil.'
        );
    }

    /** POST /api/v1/auth/logout */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->successResponse(
            data: null,
            message: 'Bearer Token berhasil dicabut (revoked). Sesi API diakhiri.'
        );
    }
}