<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Rules\PhoneCountryCode;
use App\Services\Auth\EmailOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ProfileController extends Controller
{
    /**
     * Upload or replace the authenticated user's avatar (JPG/PNG/WebP, max 2MB).
     */
    public function uploadAvatar(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'avatar' => 'required|file|image|mimes:jpg,jpeg,png,webp|max:2048',
        ], [
            'avatar.max' => 'The image must be 2MB or smaller.',
            'avatar.mimes' => 'The image must be a JPG, PNG or WebP file.',
            'avatar.image' => 'The file must be an image.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        $profile = $user->profile ?? Profile::create(['user_id' => $user->id, 'country_code' => 'AE', 'language' => 'en']);

        $this->deleteStored($profile);
        $path = $request->file('avatar')->store('avatars', 'public');
        $profile->update(['avatar_url' => $path]);

        return response()->json([
            'success' => true,
            'message' => 'Profile photo updated.',
            'data' => ['user' => $user->load(['profile', 'wallet', 'business'])],
        ]);
    }

    public function removeAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->profile) {
            $this->deleteStored($user->profile);
            $user->profile->update(['avatar_url' => null]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Profile photo removed.',
            'data' => ['user' => $user->load(['profile', 'wallet', 'business'])],
        ]);
    }

    /**
     * Signup hardening — save the account phone number (post-Google-signup
     * phone step). Same contract as AuthController@register: the dial code
     * must be on the allow-list and the number 4-15 digits; persisted as a
     * single E.164 value on users.phone (and mirrored to the legacy
     * profiles.phone column so both read paths stay consistent).
     *
     * PUT /api/v1/profile  { phone_country_code, phone_number }
     */
    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone_country_code' => ['required', 'string', new PhoneCountryCode()],
            'phone_number' => ['required', 'string', 'regex:/^\d{4,15}$/'],
        ], [
            'phone_number.regex' => 'The phone number must contain 4-15 digits only.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $phone = EmailOtpService::normalizePhone(
            $validated['phone_country_code'],
            $validated['phone_number'],
        );

        if ($phone === null) {
            // Unreachable through the validator above (defense in depth).
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => ['phone_country_code' => ['The selected phone country code is invalid.']],
            ], 422);
        }

        $user = $request->user();
        $user->update(['phone' => $phone]);

        $profile = $user->profile
            ?? Profile::create(['user_id' => $user->id, 'country_code' => 'AE', 'language' => 'en']);
        $profile->update(['phone' => $phone]);

        return response()->json([
            'success' => true,
            'message' => 'Phone number saved.',
            'data' => ['user' => $user->load(['profile', 'wallet', 'business'])],
        ]);
    }

    private function deleteStored(Profile $profile): void
    {
        $stored = $profile->getRawOriginal('avatar_url');

        if ($stored && str_starts_with($stored, 'avatars/')) {
            Storage::disk('public')->delete($stored);
        }
    }
}
