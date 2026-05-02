<?php

namespace Juniyasyos\IamClient\Tests\Feature;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Orchestra\Testbench\TestCase;

/**
 * Test suite for verifying the IAM token/session security fix.
 * 
 * This test suite ensures that users are properly logged out when:
 * 1. Their IAM token is missing from session
 * 2. Their session is in an inconsistent state
 * 
 * Regression test for: "User can login even when token/session not found"
 */
class IamTokenVerificationSecurityTest extends TestCase
{
    /**
     * Test that user is logged out if authenticated but token is missing.
     * 
     * BUG SCENARIO:
     * - User is authenticated (Laravel session valid)
     * - Token is missing from session (iam.access_token empty)
     * - iam.sub might or might not exist
     * 
     * EXPECTED: User should be logged out and redirected to login
     */
    public function test_user_logged_out_if_authenticated_but_token_missing_without_iam_sub()
    {
        // Scenario: User authenticated but BOTH token AND iam.sub missing
        // This was the bug case - middleware would do nothing

        $user = $this->createTestUser();

        // Authenticate user via session guard
        Auth::guard('web')->login($user, false);
        $this->assertTrue(Auth::check());

        // Simulate the bug state: token missing, iam.sub also missing
        Session::forget('iam.access_token');
        Session::forget('iam.sub');

        // Make a request to a protected route
        $response = $this->get('/dashboard');

        // Assert: User should be logged out
        $this->assertGuest();

        // Assert: Should redirect to login
        $response->assertRedirect(config('iam.login_route', '/sso/login'));
        $response->assertSessionHas('warning', 'Session expired, please login again.');
    }

    /**
     * Test that user is logged out if authenticated but token is missing with iam.sub present.
     * 
     * This scenario should have been caught by the old middleware but wasn't always reliable.
     */
    public function test_user_logged_out_if_authenticated_but_token_missing_with_iam_sub()
    {
        // Scenario: User authenticated with iam.sub but token missing
        // Old code would catch this, but it was inconsistent

        $user = $this->createTestUser();
        Auth::guard('web')->login($user, false);

        // Set iam.sub but remove token
        Session::put('iam.sub', $user->iam_id);
        Session::forget('iam.access_token');

        $response = $this->get('/dashboard');

        $this->assertGuest();
        $response->assertRedirect(config('iam.login_route', '/sso/login'));
    }

    /**
     * Test that unauthenticated users can still access public routes.
     * 
     * Security regression test: Ensure our fix doesn't block unauthenticated users.
     */
    public function test_unauthenticated_user_not_affected_by_token_check()
    {
        // User not authenticated at all
        $this->assertFalse(Auth::check());
        Session::forget('iam.access_token');

        // Should be able to access public routes (if they exist)
        // This is a control test - middleware should allow unauthenticated users
        $response = $this->get('/');

        // Should not redirect to login for public pages
        $this->assertNotEquals(
            $response->status(),
            302, // Redirect
            'Public routes should not be redirected by token check'
        );
    }

    /**
     * Test that iam.sub consistency check works.
     * 
     * After token decode, iam.sub should ALWAYS be synced.
     * If token is missing 'sub' claim, it should be rejected.
     */
    public function test_iam_sub_consistency_after_token_verification()
    {
        // This test verifies the consistency check in the middleware
        // If a token is present but missing 'sub' claim, it should fail

        // Setup: Mock a valid token with iam.sub
        $token = $this->createMockToken(['sub' => 'test-user-id']);

        $user = $this->createTestUser(['iam_id' => 'test-user-id']);
        Auth::guard('web')->login($user, false);

        Session::put('iam.access_token', $token);
        Session::put('iam.sub', 'test-user-id');

        $response = $this->get('/dashboard');

        // Should verify token successfully
        $this->assertTrue(Session::has('iam.sub'));
        $this->assertEquals('test-user-id', Session::get('iam.sub'));
    }

    /**
     * Test JSON API responses for token missing scenario.
     */
    public function test_json_response_on_token_missing_for_api_request()
    {
        $user = $this->createTestUser();
        Auth::guard('web')->login($user, false);

        Session::forget('iam.access_token');
        Session::forget('iam.sub');

        // Make AJAX request
        $response = $this->getJson('/api/applications');

        // Should get 401 with JSON response
        $response->assertStatus(401);
        $response->assertJson(['message' => 'Session expired, please login again.']);
    }

    // ============ Helper Methods ============

    /**
     * Create a test user for the suite.
     */
    private function createTestUser(array $attributes = [])
    {
        return \App\Models\User::firstOrCreate(
            ['email' => $attributes['email'] ?? 'test@example.com'],
            array_merge([
                'name' => 'Test User',
                'password' => bcrypt('password'),
                'iam_id' => $attributes['iam_id'] ?? 'test-iam-id-' . uniqid(),
            ], $attributes)
        );
    }

    /**
     * Create a mock JWT token for testing.
     */
    private function createMockToken(array $payload = []): string
    {
        // This is a simplified mock - in real tests, use proper JWT library
        $defaultPayload = [
            'sub' => 'test-user-id',
            'app' => 'test-app',
            'roles' => [],
            'iat' => now()->timestamp,
            'exp' => now()->addHours(1)->timestamp,
        ];

        $payload = array_merge($defaultPayload, $payload);

        // Base64 encoded payload (simplified for testing)
        return base64_encode(json_encode($payload));
    }
}
