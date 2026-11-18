<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ForgotPasswordRequest;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\PublicRegisterRequest;
use App\Http\Requests\Api\RegisterRequest;
use App\Http\Requests\Api\UpdateProfileRequest;
use App\Http\Requests\Api\UpdateUserRequest;
use App\Http\Requests\Api\VerifyEmailRequest;
use App\Mail\EmailVerificationCodeMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

class AuthController extends Controller
{
    /**
     * Register Endpoint
     * Creates a new user account with validated credentials
     * ONLY accessible by administrator
     *
     * Administrator can create admission, nurse, or doctor roles
     */
    public function register(RegisterRequest $request)
    {
        $role = $request->validated('role');

        // Additional security check: Prevent creating administrator

        // Administrator through API
        // Root user is only created via seeder
        if ($role === 'administrator') {
            Log::warning('Attempt to create administrator via API blocked', [
                'attempted_by' => $request->user()->id,
                'email' => $request->user()->email,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Root user cannot be created via API. Root user is only created through database seeding.',
            ], 403);
        }

        // Create user with validated data
        // Password will be automatically hashed by User model's 'hashed' cast
        $user = User::create([
            'user_id' => $request->validated('user_id'),
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'phone' => $request->validated('phone'),
            'password' => Hash::make($request->validated('password')),
            'role' => $role,
            'card_id' => $request->validated('card_id'),
            'email_verified_at' => now(),
        ]);

        // Log registration for audit trail
        Log::info('User registered by administrator', [
            'created_user_id' => $user->id,
            'created_email' => $user->email,
            'created_role' => $user->role,
            'registered_by' => $request->user()->id,
            'registered_by_email' => $request->user()->email,
            'ip' => $request->ip(),
        ]);

        // Return sanitized user data (exclude sensitive fields)
        return response()->json([
            'message' => 'User registered successfully',
            'user' => [
                'user_id' => $user->user_id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
                'created_at' => $user->created_at,
            ],
        ], 201);
    }

    /**
     * Public Register Endpoint
     * Creates a new user account and sends verification code
     */
    public function publicRegister(PublicRegisterRequest $request)
    {
        $verificationCode = (string) random_int(100000, 999999);

        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'phone' => $request->validated('phone'),
            'password' => Hash::make($request->validated('password')),
            'card_id' => $request->validated('card_id'),
            'role' => $request->validated('role'),
            'email_verification_code' => $verificationCode,
            'email_verification_expires_at' => now()->addMinutes(10),
        ]);

        Mail::to($user->email)->send(new EmailVerificationCodeMail($verificationCode));

        return response()->json([
            'message' => 'Verification code sent to your email.',
            'email' => $user->email,
        ], 201);
    }

    /**
     * Verify Email Endpoint
     * Confirms the email verification code for a user
     */
    public function verifyEmail(VerifyEmailRequest $request)
    {
        $user = User::where('email', $request->validated('email'))->firstOrFail();

        if ($user->email_verified_at) {
            return response()->json([
                'message' => 'Email already verified.',
            ]);
        }

        if (
            $user->email_verification_code !== $request->validated('code') ||
            ! $user->email_verification_expires_at ||
            now()->gt($user->email_verification_expires_at)
        ) {
            return response()->json([
                'message' => 'Invalid or expired verification code.',
            ], 422);
        }

        $user->update([
            'email_verified_at' => now(),
            'email_verification_code' => null,
            'email_verification_expires_at' => null,
        ]);

        return response()->json([
            'message' => 'Email verified successfully.',
        ]);
    }

    /**
     * Login Endpoint
     * Authenticates user and returns access token
     */
    public function login(LoginRequest $request)
    {
        // Authenticate user (includes rate limiting check)
        $request->authenticate();

        // Get the authenticated user (already validated in LoginRequest)
        $identifier = $request->loginIdentifier();
        $field = $request->identifierField($identifier);
        $user = User::where($field, $identifier)->firstOrFail();

        // Create token with expiration (24 hours)
        $expiresAt = now()->addYear();
        $token = $user->createToken('api_token', ['*'], $expiresAt);

        // Return sanitized user data
        return response()->json([
            'message' => 'Login successful',
            'token' => $token->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ]);
    }

    /**
     * Logout Endpoint
     * Revokes the current access token
     */
    public function logout(Request $request)
    {
        $user = $request->user();
        $token = $user->currentAccessToken();
        $tokenId = $token->id;

        // Delete the current access token
        $token->delete();

        // Log logout for audit trail
        Log::info('User logged out', [
            'user_id' => $user->id,
            'email' => $user->email,
            'token_id' => $tokenId,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Update Profile Endpoint
     * Allows authenticated users to update their own profile information
     * Users can update name, email, and/or password
     */
    public function updateProfile(UpdateProfileRequest $request)
    {
        $user = $request->user();
        $updatedFields = [];

        // Update name if provided
        if ($request->has('name')) {
            $user->name = $request->validated('name');
            $updatedFields[] = 'name';
        }

        // Update email if provided
        if ($request->has('email')) {
            $oldEmail = $user->email;
            $user->email = $request->validated('email');
            $updatedFields[] = 'email';

            // If email changed, reset email verification
            if ($oldEmail !== $user->email) {
                $user->email_verified_at = null;
            }
        }

        if ($request->has('phone')) {
            $user->phone = $request->validated('phone');
            $updatedFields[] = 'phone';
        }

        if ($request->has('notifications_enabled')) {
            $user->notifications_enabled = $request->validated('notifications_enabled');
            $updatedFields[] = 'notifications_enabled';
        }

        // Update password if provided
        if ($request->has('password')) {
            $user->password = Hash::make($request->validated('password'));
            $updatedFields[] = 'password';
        }

        // Only save if there are changes
        if (! empty($updatedFields)) {
            $user->save();

            // Log the profile update for audit trail
            Log::info('User profile updated', [
                'user_id' => $user->id,
                'email' => $user->email,
                'updated_fields' => $updatedFields,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Profile updated successfully',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role' => $user->role,
                    'email_verified_at' => $user->email_verified_at,
                    'notifications_enabled' => $user->notifications_enabled,
                    'updated_at' => $user->updated_at,
                ],
            ], 200);
        }

        // No fields to update
        return response()->json([
            'message' => 'No changes provided',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
                'notifications_enabled' => $user->notifications_enabled,
            ],
        ], 200);
    }

    /**
     * List Users Endpoint
     * Returns a list of users in the system
     * Can show active users or soft-deleted users based on query parameter
     * ONLY accessible by administrator
     */
    public function index(Request $request)
    {
        // Check if requesting deleted users
        $showDeleted = $request->query('deleted', false);

        if ($showDeleted) {
            // Get all soft-deleted users, excluding sensitive fields
            $users = User::onlyTrashed()
                ->select('id', 'user_id', 'name', 'email', 'phone', 'role', 'email_verified_at', 'notifications_enabled', 'created_at', 'updated_at', 'deleted_at')
                ->orderBy('deleted_at', 'desc')
                ->get();

            // Log deleted users list access for audit trail
            Log::info('Deleted users list accessed by administrator', [
                'accessed_by' => $request->user()->id,
                'accessed_by_email' => $request->user()->email,
                'total_deleted_users' => $users->count(),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Deleted users retrieved successfully',
                'total' => $users->count(),
                'users' => $users,
            ]);
        }

        // Get all active users (default), excluding sensitive fields
        $users = User::select('id', 'user_id', 'name', 'phone', 'email', 'role', 'email_verified_at', 'created_at', 'updated_at')
            ->orderBy('created_at', 'desc')
            ->get();

        // Log user list access for audit trail
        Log::info('User list accessed by administrator', [
            'accessed_by' => $request->user()->id,
            'accessed_by_email' => $request->user()->email,
            'total_users' => $users->count(),
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Users retrieved successfully',
            'total' => $users->count(),
            'users' => $users,
        ]);
    }

    /**
     * Send Password Reset Link Endpoint
     * Sends a password reset link to the specified user's email
     * ONLY accessible by administrator
     *
     * strator
     */
    public function sendPasswordResetLink(ForgotPasswordRequest $request)
    {
        // Find the user by user_id or email
        $user = null;
        if ($request->has('user_id')) {
            $user = User::where('user_id', $request->validated('user_id'))->first();
        } else {
            $user = User::where('email', $request->validated('email'))->first();
        }

        // Security: Always return success message to prevent user enumeration
        // Don't reveal whether user exists or not
        if (! $user) {
            Log::warning('Password reset requested for non-existent user', [
                'requested_by' => $request->user()->id,
                'requested_email' => $request->validated('email') ?? null,
                'requested_user_id' => $request->validated('user_id') ?? null,
                'ip' => $request->ip(),
            ]);

            // Return generic success message to prevent user enumeration
            return response()->json([
                'message' => 'If the email address exists in our system, a password reset link has been sent.',
            ], 200);
        }

        // Prevent root user from resetting their own password through this endpoint
        // (They should use the standard forgot password flow)
        if ($user->role === 'administrator') {
            Log::warning('Attempt to send password reset link to administrator via admin endpoint blocked', [
                'attempted_by' => $request->user()->id,
                'target_user_id' => $user->id,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Cannot send password reset link to root user through this endpoint.',
            ], 403);
        }

        // Send password reset link
        $status = Password::sendResetLink(
            ['email' => $user->email]
        );

        // Log the action for audit trail
        Log::info('Password reset link sent by administrator', [
            'target_user_id' => $user->id,
            'target_email' => $user->email,
            'sent_by' => $request->user()->id,
            'sent_by_email' => $request->user()->email,
            'status' => $status,
            'ip' => $request->ip(),
        ]);

        // Always return generic success message to prevent information disclosure
        // Don't reveal whether email was actually sent or user details
        return response()->json([
            'message' => 'If the email address exists in our system, a password reset link has been sent.',
        ], 200);
    }

    /**
     * Delete User Endpoint
     * Deletes a user from the system
     * ONLY accessible by administrator
     */
    public function destroy(Request $request, $id)
    {
        // Validate that id is a valid integer
        if (! is_numeric($id) || (int) $id != $id) {
            return response()->json([
                'message' => 'Invalid user ID provided.',
            ], 400);
        }

        $user = $this->resolveUser($id);

        if (! $user) {
            return response()->json([
                'message' => 'The specified user does not exist.',
            ], 404);
        }

        // Prevent deleting root user
        if ($user->role === 'administrator') {
            Log::warning('Attempt to delete administrator blocked', [
                'attempted_by' => $request->user()->id,
                'target_user_id' => $user->id,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Cannot delete root user. Root user cannot be removed from the system.',
            ], 403);
        }

        // Store user info for logging before deletion
        $deletedUserInfo = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
        ];

        DB::table('sessions')->where('user_id', $user->id)->delete();

        // Delete the user permanently
        $user->forceDelete();

        // Log the deletion for audit trail
        Log::info('User deleted by administrator', [
            'deleted_user_id' => $deletedUserInfo['id'],
            'deleted_email' => $deletedUserInfo['email'],
            'deleted_role' => $deletedUserInfo['role'],
            'deleted_by' => $request->user()->id,
            'deleted_by_email' => $request->user()->email,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'User permanently deleted successfully.',
            'deleted_user' => $deletedUserInfo,
        ], 200);
    }

    /**
     * Permanently Delete User Endpoint
     * Permanently deletes a soft-deleted user from the system
     * ONLY accessible by administrator
     */
    public function forceDestroy(Request $request, $id)
    {
        if (! is_numeric($id) || (int) $id != $id) {
            return response()->json([
                'message' => 'Invalid user ID provided.',
            ], 400);
        }

        $user = $this->resolveUser($id, true);

        if (! $user) {
            return response()->json([
                'message' => 'The specified user does not exist.',
            ], 404);
        }

        if ($user->role === 'administrator') {
            Log::warning('Attempt to permanently delete administrator blocked', [
                'attempted_by' => $request->user()->id,
                'target_user_id' => $user->id,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Cannot delete root user. Root user cannot be removed from the system.',
            ], 403);
        }

        DB::table('sessions')->where('user_id', $user->id)->delete();

        $deletedUserInfo = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'deleted_at' => $user->deleted_at,
        ];

        $user->forceDelete();

        Log::info('User permanently deleted by administrator', [
            'deleted_user_id' => $deletedUserInfo['id'],
            'deleted_email' => $deletedUserInfo['email'],
            'deleted_role' => $deletedUserInfo['role'],
            'deleted_by' => $request->user()->id,
            'deleted_by_email' => $request->user()->email,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'User permanently deleted successfully.',
            'deleted_user' => $deletedUserInfo,
        ], 200);
    }

    /**
     * Update User Endpoint
     * Updates a user in the system
     * ONLY accessible by administrator
     */
    public function updateUser(UpdateUserRequest $request, $id)
    {
        if (! is_numeric($id) || (int) $id != $id) {
            return response()->json([
                'message' => 'Invalid user ID provided.',
            ], 400);
        }

        $user = $this->resolveUser($id);

        if (! $user) {
            return response()->json([
                'message' => 'The specified user does not exist.',
            ], 404);
        }

        if ($user->role === 'administrator') {
            Log::warning('Attempt to update administrator blocked', [
                'attempted_by' => $request->user()->id,
                'target_user_id' => $user->id,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Cannot update root user through this endpoint.',
            ], 403);
        }

        if ($request->has('user_id')) {
            $user->user_id = $request->validated('user_id');
            $updatedFields[] = 'user_id';
        }

        $updatedFields = [];

        if ($request->has('name')) {
            $user->name = $request->validated('name');
            $updatedFields[] = 'name';
        }

        if ($request->has('email')) {
            $oldEmail = $user->email;
            $user->email = $request->validated('email');
            $updatedFields[] = 'email';

            if ($oldEmail !== $user->email) {
                $user->email_verified_at = null;
            }
        }

        if ($request->has('phone')) {
            $user->phone = $request->validated('phone');
            $updatedFields[] = 'phone';
        }

        if ($request->has('role')) {
            $user->role = $request->validated('role');
            $updatedFields[] = 'role';
        }

        if ($request->has('notifications_enabled')) {
            $user->notifications_enabled = $request->validated('notifications_enabled');
            $updatedFields[] = 'notifications_enabled';
        }

        if ($request->has('password')) {
            $user->password = Hash::make($request->validated('password'));
            $updatedFields[] = 'password';
        }

        if (! empty($updatedFields)) {
            $user->save();

            Log::info('User updated by administrator', [
                'updated_user_id' => $user->id,
                'updated_email' => $user->email,
                'updated_fields' => $updatedFields,
                'updated_by' => $request->user()->id,
                'updated_by_email' => $request->user()->email,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'User updated successfully',
                'user' => [
                    'id' => $user->id,
                    'user_id' => $user->user_id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role' => $user->role,
                    'email_verified_at' => $user->email_verified_at,
                    'notifications_enabled' => $user->notifications_enabled,
                    'updated_at' => $user->updated_at,
                ],
            ], 200);
        }

        return response()->json([
            'message' => 'No changes provided',
            'user' => [
                'id' => $user->id,
                'user_id' => $user->user_id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
                'notifications_enabled' => $user->notifications_enabled,
            ],
        ], 200);
    }

    /**
     * Restore User Endpoint
     * Restores a soft-deleted user
     * ONLY accessible by administrator
     */
    public function restore(Request $request, $id)
    {
        // Validate that id is a valid integer
        if (! is_numeric($id) || (int) $id != $id) {
            return response()->json([
                'message' => 'Invalid user ID provided.',
            ], 400);
        }

        // Find user including soft deleted users
        $user = $this->resolveUser($id, true);

        if (! $user) {
            return response()->json([
                'message' => 'The specified user does not exist.',
            ], 404);
        }

        // Check if user is actually deleted
        if (! $user->trashed()) {
            return response()->json([
                'message' => 'User is not deleted. Nothing to restore.',
            ], 400);
        }

        // Prevent restoring root user (should not be deleted in first place)
        if ($user->role === 'administrator') {
            Log::warning('Attempt to restore administrator blocked', [
                'attempted_by' => $request->user()->id,
                'target_user_id' => $user->id,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'Cannot restore root user through this endpoint.',
            ], 403);
        }

        // Store user info for logging before restoration
        $restoredUserInfo = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'deleted_at' => $user->deleted_at,
        ];

        // Restore the user
        $user->restore();

        // Log the restoration for audit trail
        Log::info('User restored by administrator', [
            'restored_user_id' => $restoredUserInfo['id'],
            'restored_email' => $restoredUserInfo['email'],
            'restored_role' => $restoredUserInfo['role'],
            'was_deleted_at' => $restoredUserInfo['deleted_at'],
            'restored_by' => $request->user()->id,
            'restored_by_email' => $request->user()->email,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'User restored successfully',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'deleted_at' => null,
                'restored_at' => now(),
            ],
        ], 200);
    }

    private function resolveUser($identifier, bool $withTrashed = false): ?User
    {
        $query = User::query();

        if ($withTrashed) {
            $query->withTrashed();
        }

        $identifier = (string) $identifier;

        if (preg_match('/^\d{5}$/', $identifier)) {
            $user = $query->where('user_id', $identifier)->first();

            if ($user) {
                return $user;
            }
        }

        if (is_numeric($identifier)) {
            return $query->find((int) $identifier);
        }

        return null;
    }
}
