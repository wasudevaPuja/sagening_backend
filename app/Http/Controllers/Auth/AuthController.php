<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Http\Controllers\Controller;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function login(Request $request)
    {

        try {
            $validator = Validator::make(
                $request->all(),
                [
                    'username' => 'required|string',
                    'password' => 'required|string|min:6',
                ],
                [
                    'username.required' => 'Isikan Username',
                    'password.required' => 'Isikan Password',
                    'password.min' => 'Password harus memiliki minimal 6 karakter'
                ]
            );

            if ($validator->fails()) {
                return response()->json([
                    'error' => true,
                    'messages' => $validator->errors()
                ], 422);
            }

            $user = User::where('username', $request->username)->first();

            if (!$user || !Hash::check($request->password, $user->password)) {
                return response()->json([
                    'error' => true,
                    'messages' => 'Username atau password salah'
                ],422);
            }

            // Hapus token lama jika ada
            $user->tokens()->delete();

            $accessToken = $user->createToken('auth_token')->plainTextToken;
            $refreshToken = Str::random(64);
            $expiresAt = Carbon::now()->addHour(); // Refresh token berlaku 1 jam

            $tokenModel = PersonalAccessToken::where('tokenable_id', $user->id)
                ->latest()
                ->first();

            if ($tokenModel) {
                $tokenModel->refresh_token = $refreshToken;
                $tokenModel->refresh_expires_at = $expiresAt;
                $tokenModel->save();
            }

            return response()->json([
                'error' => false,
                'messages' => 'Berhasil mendapatkan token',
                'data' => (object) ['token' => $accessToken, 'refresh_token' => $tokenModel->refresh_token]
            ]);
        } catch (\Throwable $th) {
            dd($th);
            return response()->json([
                'error' => true,
                'messages' => 'Terjadi Kesalahan pada Server',
            ]);
        }
    }

    public function logout(Request $request)
    {
        try {
            if (!$request->user()) {
                return response()->json([
                    'error' => false,
                    'messages' => 'Token anda tidak valid',
                ], 401);
            }

            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'error' => false,
                'messages' => 'Anda telah logout',
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'error' => true,
                'messages' => 'Terjadi Kesalahan pada Server',
            ]);
        }
    }

    public function refreshToken(Request $request)
    {
        try {

            if ($request->refresh_token == null) {
                return response()->json([
                    'error' => true,
                    'messages' => 'Isikan Refresh Token'
                ]);
            }

            $token = PersonalAccessToken::where('refresh_token', $request->refresh_token)->first();

            if (!$token || Carbon::parse($token->refresh_expires_at)->isPast()) {
                return response()->json([
                    'error' => true,
                    'messages' => 'Token Anda Kadaluarsa',
                ]);
            }

            // Hapus access token lama
            $token->delete();

            // Buat access token dan refresh token baru

            $user = User::where('id', $token->tokenable_id)->first();
            $accessToken = $user->createToken('auth_token')->plainTextToken;
            $newRefreshToken = Str::random(64);
            $expiresAt = Carbon::now()->addHour(); // Refresh token berlaku 1 jam

            // Simpan refresh token baru
            $newToken = PersonalAccessToken::where('tokenable_id', $user->id)->latest()->first();
            if ($newToken) {
                $newToken->refresh_token = $newRefreshToken;
                $newToken->refresh_expires_at = $expiresAt;
                $newToken->save();
            }

            return response()->json([
                'error' => false,
                'messages' => 'Berhasil mendapatkan token',
                'data' => (object) ['token' => $accessToken, 'refresh_token' => $newToken->refresh_token]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'error' => true,
                'messages' => 'Terjadi Kesalahan pada Server',
            ]);
        }
    }
}
