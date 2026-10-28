<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Login do usuário e geração de token Sanctum
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['As credenciais fornecidas são inválidas.'],
            ]);
        }

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'admin' => $user->admin,
                'type' => $user->type, // doctor | employee
                'profile_id' => $user->profile_id,
                'unit_addresses_id' => $user->unit_addresses_id,
            ],
            'token' => $user->createToken('api-token')->plainTextToken,
        ]);
    }

    /**
     * Logout e revogação do token
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        // Sai do chat na hora, sem esperar o sinal de online expirar.
        app(ChatService::class)->forget($request->user());

        return response()->json(['message' => 'Logout realizado com sucesso.']);
    }
}