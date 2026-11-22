<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Ledger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'alias' => ['required', 'string', 'min:3', 'max:30', 'regex:/^[\p{L}\p{N}_ ]+$/u', 'unique:users,alias'],
            'mobile' => ['required', 'string', 'regex:/^05\d{8}$/', 'unique:users,mobile'],
            'password' => ['required', 'string', Password::min(8)],
        ]);

        [$user, $granted] = DB::transaction(function () use ($data) {
            $user = User::create($data);

            return [$user, Ledger::grantStartingBalance($user->id)];
        });

        return response()->json([
            'token' => $user->createToken('api')->plainTextToken,
            'user' => $this->profile($user),
            'starting_balance' => $granted,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mobile' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('mobile', $data['mobile'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'رقم الجوال أو كلمة المرور غير صحيحة'], 401);
        }
        if ($user->status !== 'active' || $user->role === 'bot') {
            return response()->json(['message' => 'الحساب موقوف'], 403);
        }

        return response()->json([
            'token' => $user->createToken('api')->plainTextToken,
            'user' => $this->profile($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'تم تسجيل الخروج']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->profile($request->user())]);
    }

    private function profile(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'alias' => $user->alias,
            'role' => $user->role ?? 'trader',
            'forum_signature' => (string) ($user->forum_signature ?? ''),
            'balances' => Ledger::balances($user->id),
        ];
    }
}
