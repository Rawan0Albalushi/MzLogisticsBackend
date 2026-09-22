<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterCustomerRequest;
use App\Http\Requests\Auth\RegisterProviderRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    public function registerCustomer(RegisterCustomerRequest $request): JsonResponse
    {
        $result = $this->authService->registerCustomer($request->validated());

        return ApiResponse::success([
            'token' => $result['token'],
            'user' => UserResource::make($result['user']),
        ], 'Account created.', 201);
    }

    public function registerProvider(RegisterProviderRequest $request): JsonResponse
    {
        $result = $this->authService->registerProvider($request->validated());

        return ApiResponse::success([
            'token' => $result['token'],
            'user' => UserResource::make($result['user']),
        ], 'Provider account created and pending review.', 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login($request->identifier(), $request->string('password')->toString());

        return ApiResponse::success([
            'token' => $result['token'],
            'user' => UserResource::make($result['user']),
        ], 'Signed in.');
    }

    public function activateDriver(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $result = $this->authService->activateDriver($data['token'], $data['password']);

        return ApiResponse::success([
            'token' => $result['token'],
            'user' => UserResource::make($result['user']),
        ], 'Account activated.');
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return ApiResponse::success(null, 'Signed out.');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['organization', 'roles', 'permissions', 'driverProfile']);

        return ApiResponse::success(UserResource::make($user));
    }

    public function updateMe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'locale' => ['sometimes', 'in:ar,en'],
        ]);

        $request->user()->fill($data)->save();

        return ApiResponse::success(UserResource::make($request->user()->fresh(['organization', 'roles', 'permissions'])));
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $request->user()->forceFill(['password' => $data['password']])->save();

        return ApiResponse::success(null, 'Password updated.');
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        $status = $this->authService->sendResetLink($request->string('email')->toString());

        if ($status !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return ApiResponse::success(null, __($status));
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = $this->authService->resetPassword($data);
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return ApiResponse::success(null, __($status));
    }
}
