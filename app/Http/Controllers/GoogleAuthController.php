<?php
// FILE: app/Http/Controllers/GoogleAuthController.php
// CHANGES FROM PREVIOUS VERSION:
// 1. connect(): added prompt=select_account removed — kept prompt=consent (critical for refresh_token)
// 2. callback(): if google_refresh_token already exists in DB and Google doesn't return a new one,
//    we KEEP the existing one — this prevents accidental token loss on re-auth
// 3. status(): isFuture() check uses a 5-minute buffer so we refresh proactively before expiry
// 4. tryRefreshToken(): unchanged but surfaced to show it works correctly

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class GoogleAuthController extends Controller
{
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;

    public function __construct()
    {
        $this->clientId     = env('GOOGLE_CLIENT_ID', '');
        $this->clientSecret = env('GOOGLE_CLIENT_SECRET', '');
        $this->redirectUri  = env('GOOGLE_REDIRECT_URI', config('app.url') . '/api/google/callback');
    }

    /**
     * GET /api/google/connect  (auth:sanctum)
     * Returns the Google OAuth URL.
     * prompt=consent is CRITICAL — Google only returns refresh_token when this is set.
     * Without refresh_token the user must reconnect every hour.
     */
    public function connect(Request $request)
    {
        $userId = auth()->id();
        if (!$userId) return response()->json(['error' => 'Unauthenticated'], 401);

        $params = http_build_query([
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'response_type' => 'code',
            'scope'         => implode(' ', [
                'https://www.googleapis.com/auth/calendar.events',
                'https://www.googleapis.com/auth/calendar',
                'email',
                'profile',
            ]),
            'access_type'   => 'offline',   // REQUIRED: tells Google to issue refresh_token
            'prompt'        => 'consent',   // REQUIRED: forces Google to return refresh_token every time
            'state'         => base64_encode(json_encode(['user_id' => $userId])),
        ]);

        return response()->json(['auth_url' => "https://accounts.google.com/o/oauth2/v2/auth?{$params}"]);
    }

    /**
     * GET /api/google/callback  (public — Google redirects here)
     * Exchanges code for tokens and saves to MAIN DB.
     *
     * KEY FIX: If Google returns a new refresh_token, we store it.
     * If Google does NOT return one (shouldn't happen with prompt=consent but just in case),
     * we keep the existing refresh_token from the DB instead of overwriting it with null.
     */
    public function callback(Request $request)
    {
        $code        = $request->query('code');
        $state       = $request->query('state');
        $error       = $request->query('error');
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:5174');

        if ($error || !$code) {
            Log::warning('Google OAuth callback error', ['error' => $error]);
            return redirect($frontendUrl . '/settings?google=error&msg=' . urlencode($error ?? 'No code'));
        }

        $stateData = json_decode(base64_decode($state ?? ''), true);
        $userId    = $stateData['user_id'] ?? null;

        if (!$userId) {
            return redirect($frontendUrl . '/settings?google=error&msg=invalid_state');
        }

        // Exchange code for tokens
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code'          => $code,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri'  => $this->redirectUri,
            'grant_type'    => 'authorization_code',
        ]);

        if (!$response->successful()) {
            Log::error('Google OAuth token exchange failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return redirect($frontendUrl . '/settings?google=error&msg=token_exchange_failed');
        }

        $tokens = $response->json();

        $user = DB::connection('mysql')->table('users')->where('id', $userId)->first();

        if (!$user) {
            Log::error('Google callback: user not found', ['user_id' => $userId]);
            return redirect($frontendUrl . '/settings?google=error&msg=user_not_found');
        }

        // ── KEY FIX ──────────────────────────────────────────────────────────
        // Google returns refresh_token with prompt=consent.
        // But if for any reason it's missing, keep the existing one — never null it out.
        // This ensures the token stays valid long-term even if the user re-authenticates.
        $newRefreshToken      = $tokens['refresh_token'] ?? null;
        $existingRefreshToken = $user->google_refresh_token ?? null;
        $refreshToken         = $newRefreshToken ?? $existingRefreshToken;
        // ─────────────────────────────────────────────────────────────────────

        if (!$refreshToken) {
            Log::warning('Google OAuth: No refresh_token for user ' . $userId . '. Token will expire in 1hr and need manual reconnect.');
        }

        DB::connection('mysql')->table('users')->where('id', $userId)->update([
            'google_access_token'     => $tokens['access_token'],
            'google_refresh_token'    => $refreshToken,   // never set to null
            'google_token_expires_at' => now()->addSeconds($tokens['expires_in'] ?? 3600),
            'updated_at'              => now(),
        ]);

        Log::info('Google Calendar connected for user ' . $userId, [
            'has_refresh_token'  => !empty($refreshToken),
            'got_new_refresh'    => !empty($newRefreshToken),
            'kept_old_refresh'   => empty($newRefreshToken) && !empty($existingRefreshToken),
            'expires_in'         => $tokens['expires_in'] ?? 'unknown',
        ]);

        return redirect($frontendUrl . '/settings?google=connected');
    }

    /**
     * GET /api/google/status  (auth:sanctum)
     * Returns connection status.
     *
     * KEY FIX: We try to refresh the token proactively if it expires within 5 minutes,
     * rather than waiting for it to fully expire. This prevents any window where the
     * token appears expired to the frontend even though we could refresh it.
     */
    public function status(Request $request)
    {
        $userId = auth()->id();
        if (!$userId) return response()->json(['error' => 'Unauthenticated'], 401);

        $user = DB::connection('mysql')->table('users')->where('id', $userId)->first();

        $connected  = !empty($user?->google_access_token);
        $hasRefresh = !empty($user?->google_refresh_token);
        $expired    = false;

        if ($connected && $user->google_token_expires_at) {
            // Consider token "expiring soon" within 5 minutes — refresh proactively
            $expiresAt = Carbon::parse($user->google_token_expires_at);
            $expiringSoon = $expiresAt->isBefore(now()->addMinutes(5));

            if ($expiringSoon) {
                if ($hasRefresh) {
                    // Auto-refresh silently
                    $newToken = $this->tryRefreshToken($user);
                    if (!$newToken) {
                        // Refresh failed — token is expired
                        $expired = $expiresAt->isPast();
                    }
                    // If refresh succeeded, $expired stays false
                } else {
                    // No refresh token — mark as expired if actually past
                    $expired = $expiresAt->isPast();
                }
            }
        }

        return response()->json([
            'connected'   => $connected,
            'expired'     => $expired,
            'has_refresh' => $hasRefresh,
        ]);
    }

    /**
     * POST /api/google/disconnect  (auth:sanctum)
     * Revokes token at Google and clears from DB.
     */
    public function disconnect(Request $request)
    {
        $userId = auth()->id();
        if (!$userId) return response()->json(['error' => 'Unauthenticated'], 401);

        $user = DB::connection('mysql')->table('users')->where('id', $userId)->first();

        if (!empty($user?->google_access_token)) {
            try {
                Http::post('https://oauth2.googleapis.com/revoke', [
                    'token' => $user->google_access_token,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Google token revoke failed (may already be expired): ' . $e->getMessage());
            }
        }

        DB::connection('mysql')->table('users')->where('id', $userId)->update([
            'google_access_token'     => null,
            'google_refresh_token'    => null,
            'google_token_expires_at' => null,
            'updated_at'              => now(),
        ]);

        Log::info('Google Calendar disconnected for user ' . $userId);

        return response()->json(['message' => 'Google Calendar disconnected successfully']);
    }

    /**
     * Silently refreshes the access token using the stored refresh_token.
     * Returns new access token on success, null on failure.
     */
    private function tryRefreshToken(object $user): ?string
    {
        if (empty($user->google_refresh_token)) {
            return null;
        }

        try {
            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'refresh_token' => $user->google_refresh_token,
                'grant_type'    => 'refresh_token',
            ]);

            if ($response->successful()) {
                $data = $response->json();
                DB::connection('mysql')->table('users')->where('id', $user->id)->update([
                    'google_access_token'     => $data['access_token'],
                    'google_token_expires_at' => now()->addSeconds($data['expires_in'] ?? 3600),
                    'updated_at'              => now(),
                ]);
                Log::info('Google token auto-refreshed for user ' . $user->id);
                return $data['access_token'];
            }

            Log::warning('Google token auto-refresh failed', [
                'user_id' => $user->id,
                'status'  => $response->status(),
                'body'    => $response->body(),
            ]);
            return null;
        } catch (\Throwable $e) {
            Log::error('Google token refresh exception: ' . $e->getMessage());
            return null;
        }
    }
}
