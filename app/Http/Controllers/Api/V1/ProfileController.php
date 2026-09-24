<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\User;
use App\Rules\PhoneCountryCode;
use App\Rules\StrongPassword;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\EmailOtpService;
use App\Services\Email\EmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ProfileController extends Controller
{
    /**
     * Change the signed-in user's password.
     *
     * PUT /api/v1/profile/password
     *   { current_password, password, password_confirmation }
     *
     * Same strong-password policy as signup/reset. On success every OTHER
     * session (API token) is revoked so a stolen token stops working, the
     * current session stays signed in, and a "password changed" email goes
     * out so the owner notices if it wasn't them.
     */
    public function updatePassword(Request $request, EmailService $emails): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'password' => ['required', 'string', 'max:128', new StrongPassword(), 'confirmed', 'different:current_password'],
        ], [
            'password.confirmed' => 'The new passwords do not match.',
            'password.different' => 'Choose a new password that is different from your current one.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();

        if (!Hash::check((string) $request->input('current_password'), $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Your current password is incorrect.',
                'errors' => ['current_password' => ['Your current password is incorrect.']],
            ], 422);
        }

        $user->forceFill(['password' => Hash::make((string) $request->input('password'))])->save();

        // Sign out every other device; keep this session.
        $current = $user->currentAccessToken();
        $others = $user->tokens();
        if ($current && isset($current->id)) {
            $others->where('id', '!=', $current->id);
        }
        $revoked = $others->delete();

        AuditLogger::log($user, 'user.password_changed', User::class, $user->id, ['other_sessions_revoked' => $revoked]);
        $emails->sendEvent('password_changed', $user->email, ['user_name' => $user->name]);

        return response()->json([
            'success' => true,
            'message' => $revoked > 0
                ? 'Password updated. You were signed out on your other devices.'
                : 'Password updated.',
        ]);
    }

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
     * The same endpoint also saves the Personal Information form on the
     * profile page. Every field is optional, but at least one must be sent:
     *
     * PUT /api/v1/profile  { phone_country_code, phone_number }
     *                      { phone: "+971501234567" }   (single E.164 value)
     *                      { name, country_code, city, bio }
     *
     * Email is the login identity and is intentionally not editable here.
     */
    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone_country_code' => ['required_with:phone_number', 'string', new PhoneCountryCode()],
            'phone_number' => ['required_with:phone_country_code', 'string', 'regex:/^\d{4,15}$/'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:25'],
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'country_code' => ['sometimes', 'required', 'string', 'size:2', 'alpha'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ], [
            'phone_number.regex' => 'The phone number must contain 4-15 digits only.',
            'country_code.size' => 'Select a valid country.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        if ($validated === []) {
            return response()->json([
                'success' => false,
                'message' => 'Nothing to update.',
            ], 422);
        }

        $phone = null;
        if (isset($validated['phone_country_code'], $validated['phone_number'])) {
            $phone = EmailOtpService::normalizePhone($validated['phone_country_code'], $validated['phone_number']);
            if ($phone === null) {
                // Unreachable through the validator above (defense in depth).
                return $this->phoneError('phone_country_code', 'The selected phone country code is invalid.');
            }
        } elseif (!empty($validated['phone'])) {
            $phone = $this->normalizeE164($validated['phone']);
            if ($phone === null) {
                return $this->phoneError('phone', 'Enter your phone with country code, e.g. +971501234567.');
            }
        }

        $user = $request->user();

        $userData = [];
        if ($phone !== null) {
            $userData['phone'] = $phone;
        }
        if (isset($validated['name'])) {
            $userData['name'] = trim($validated['name']);
        }
        if ($userData !== []) {
            $user->update($userData);
        }

        $profileData = array_intersect_key($validated, array_flip(['country_code', 'city', 'bio']));
        if (isset($profileData['country_code'])) {
            $profileData['country_code'] = strtoupper($profileData['country_code']);
        }
        if ($phone !== null) {
            $profileData['phone'] = $phone;
        }
        if ($profileData !== []) {
            $profile = Profile::firstOrCreate(['user_id' => $user->id], ['country_code' => 'AE', 'language' => 'en']);
            $profile->update($profileData);
        }

        $onlyPhone = array_diff(array_keys($validated), ['phone', 'phone_country_code', 'phone_number']) === [];

        return response()->json([
            'success' => true,
            'message' => $onlyPhone ? 'Phone number saved.' : 'Profile updated.',
            'data' => ['user' => $user->fresh()->load(['profile', 'wallet', 'business'])],
        ]);
    }

    /**
     * "+971 50-123 4567" -> "+971501234567" when the dial code is on the
     * allow-list (config/phone.php) and 4-15 national digits follow. ITU
     * calling codes are prefix-free, so the first matching length wins.
     */
    private function normalizeE164(string $raw): ?string
    {
        $digits = preg_replace('/[\s\-().]/', '', trim($raw));
        if (!preg_match('/^\+?(\d{5,19})$/', $digits, $m)) {
            return null;
        }

        for ($len = 1; $len <= 4; $len++) {
            $phone = EmailOtpService::normalizePhone(substr($m[1], 0, $len), substr($m[1], $len));
            if ($phone !== null) {
                return $phone;
            }
        }

        return null;
    }

    private function phoneError(string $field, string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => [$field => [$message]],
        ], 422);
    }

    /**
     * Submit identity documents for KYC review. Files go to the private
     * `local` disk; staff review them through /staff/kyc.
     *
     * POST /api/v1/profile/kyc  (multipart)
     *   document_type: emirates_id | passport | national_id
     *   document_front (required), document_back, selfie
     */
    public function submitKyc(Request $request, EmailService $emails): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'document_type' => 'required|in:emirates_id,passport,national_id',
            'document_front' => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
            'document_back' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
            'selfie' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:5120',
        ], [
            'document_front.required' => 'Upload the front of your ID document.',
            'document_front.max' => 'Each file must be 5MB or smaller.',
            'document_back.max' => 'Each file must be 5MB or smaller.',
            'selfie.max' => 'Each file must be 5MB or smaller.',
            'document_front.mimes' => 'Documents must be JPG, PNG, WebP or PDF.',
            'document_back.mimes' => 'Documents must be JPG, PNG, WebP or PDF.',
            'selfie.mimes' => 'The selfie must be a JPG, PNG or WebP image.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        // Read the profile fresh: the status checks below must see the
        // current row, not a relation cached on the user instance.
        $profile = Profile::firstOrCreate(['user_id' => $user->id], ['country_code' => 'AE', 'language' => 'en']);

        if ($profile->kyc_status === 'verified') {
            return response()->json(['success' => false, 'message' => 'Your identity is already verified.'], 422);
        }
        if ($profile->kyc_status === 'pending') {
            return response()->json(['success' => false, 'message' => 'Your documents are already under review.'], 422);
        }

        // A resubmission after rejection replaces the previous files.
        $this->deleteKycFiles($profile);

        $dir = 'kyc/' . $user->id;
        $profile->update([
            'kyc_status' => 'pending',
            'kyc_document_type' => $request->input('document_type'),
            'kyc_front_path' => $request->file('document_front')->store($dir, 'local'),
            'kyc_back_path' => $request->hasFile('document_back') ? $request->file('document_back')->store($dir, 'local') : null,
            'kyc_selfie_path' => $request->hasFile('selfie') ? $request->file('selfie')->store($dir, 'local') : null,
            'kyc_submitted_at' => now(),
            'kyc_verified_at' => null,
            'kyc_reviewed_by' => null,
            'kyc_rejection_reason' => null,
        ]);

        AuditLogger::log($user, 'kyc.submitted', User::class, $user->id, [
            'document_type' => $request->input('document_type'),
        ]);

        $emails->sendEvent('kyc_submitted', $user->email, ['user_name' => $user->name]);

        return response()->json([
            'success' => true,
            'message' => 'Documents submitted. Our team will review them shortly.',
            'data' => ['user' => $user->fresh()->load(['profile', 'wallet', 'business'])],
        ]);
    }

    private function deleteKycFiles(Profile $profile): void
    {
        foreach (['kyc_front_path', 'kyc_back_path', 'kyc_selfie_path'] as $column) {
            $path = $profile->getAttributes()[$column] ?? null;
            if ($path) {
                Storage::disk('local')->delete($path);
            }
        }
    }

    private function deleteStored(Profile $profile): void
    {
        $stored = $profile->getRawOriginal('avatar_url');

        if ($stored && str_starts_with($stored, 'avatars/')) {
            Storage::disk('public')->delete($stored);
        }
    }
}
