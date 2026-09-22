<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Profile;
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

    private function deleteStored(Profile $profile): void
    {
        $stored = $profile->getRawOriginal('avatar_url');

        if ($stored && str_starts_with($stored, 'avatars/')) {
            Storage::disk('public')->delete($stored);
        }
    }
}
