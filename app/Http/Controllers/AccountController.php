<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    /**
     * Get current logged-in user's account information.
     */
    public function show(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'role' => $user->role,
                'profile_photo' => $user->profile_photo,
                'profile_photo_url' => $user->profile_photo
                    ? url('/storage/' . $user->profile_photo)
                    : null,
            ],
        ]);
    }

    /**
     * Update name and email.
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
        ]);

        // QRPASS_ACCOUNT_SAVE_ONLY_V1
        $photo = $request->validate([
            'photo_action' => ['nullable', Rule::in(['replace', 'remove'])],
            'profile_photo' => ['required_if:photo_action,replace', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);
        $previousPhoto = $user->profile_photo;
        $newPhoto = null;
        if (($photo['photo_action'] ?? null) === 'replace') {
            $newPhoto = $request->file('profile_photo')->store('profile-photos', 'public');
            if (!$newPhoto || !Storage::disk('public')->exists($newPhoto)) {
                return response()->json(['message' => 'Unable to save profile photo.'], 500);
            }
        }
        try {
            $user->name = $validated['name'];
            $user->email = $validated['email'] ?? null;
            if (($photo['photo_action'] ?? null) === 'replace') $user->profile_photo = $newPhoto;
            if (($photo['photo_action'] ?? null) === 'remove') $user->profile_photo = null;
            $user->save();
        } catch (\Throwable $e) {
            if ($newPhoto) Storage::disk('public')->delete($newPhoto);
            throw $e;
        }
        if ($previousPhoto && $previousPhoto !== $user->profile_photo) {
            try { Storage::disk('public')->delete($previousPhoto); } catch (\Throwable $e) { report($e); }
        }

        return response()->json([
            'message' => 'Account information updated successfully.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'role' => $user->role,
                'profile_photo' => $user->profile_photo,
                'profile_photo_url' => $user->profile_photo
                    ? url('/storage/' . $user->profile_photo)
                    : null,
            ],
        ]);
    }

    /**
     * Change account password.
     */
    public function updatePassword(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => [
                'required',
                'string',
            ],

            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
            ],
        ]);

        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect.',
                'errors' => [
                    'current_password' => [
                        'Current password is incorrect.',
                    ],
                ],
            ], 422);
        }

        $user->password = Hash::make($validated['password']);
        $user->save();

        return response()->json([
            'message' => 'Password changed successfully.',
        ]);
    }

    /**
     * Upload or replace profile photo.
     */
    public function uploadProfilePhoto(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'profile_photo' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
            ],
        ]);

        // QRPASS_ACCOUNT_PHOTO_SYNC_V1
        $previousPhoto = $user->profile_photo;

        $path = $request
            ->file('profile_photo')
            ->store('profile-photos', 'public');

        if (!$path || !Storage::disk('public')->exists($path)) {
            return response()->json(['message' => 'Unable to save profile photo.'], 500);
        }
        try {
            $user->profile_photo = $path;
            $user->save();
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($path);
            throw $e;
        }
        if ($previousPhoto && $previousPhoto !== $path) {
            Storage::disk('public')->delete($previousPhoto);
        }

        return response()->json([
            'message' => 'Profile photo updated successfully.',
            'profile_photo' => $path,
            'profile_photo_url' => url('/storage/' . $path),
        ]);
    }

    /**
     * Remove profile photo.
     */
    public function removeProfilePhoto(Request $request)
    {
        $user = $request->user();

        if ($user->profile_photo) {
            Storage::disk('public')->delete($user->profile_photo);

            $user->profile_photo = null;
            $user->save();
        }

        return response()->json([
            'message' => 'Profile photo removed successfully.',
            'profile_photo' => null,
            'profile_photo_url' => null,
        ]);
    }
}