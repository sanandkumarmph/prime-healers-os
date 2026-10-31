<?php

namespace Tests\Feature\Regression;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestData;
use Tests\TestCase;

class FreshFileSessionRegressionTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;
    private array $cookies = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->directory = storage_path('framework/role-session-test-'.bin2hex(random_bytes(8)));
        mkdir($this->directory, 0700);
        config(['session.driver' => 'file', 'session.files' => $this->directory,
            'session.cookie' => 'phos_role_session_test', 'session.secure' => false,
            'app.url' => 'http://localhost']);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $path) {
            $this->assertSame($this->directory, dirname($path));
            $this->assertTrue(is_file($path) && !is_link($path));
            unlink($path);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    private function browserRequest(string $method, string $path, array $data = [])
    {
        // A browser round trip must reload both guard and session from cookies;
        // retaining the test's authenticated guard would hide this regression.
        Auth::forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $request = Request::create('http://localhost'.$path, $method, $data, $this->cookies);
        $kernel = $this->app->make(Kernel::class);
        $response = $kernel->handle($request);
        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = $cookie->getValue();
        }
        $kernel->terminate($request, $response);
        return $response;
    }

    #[DataProvider('rememberOptions')]
    public function test_fresh_file_login_survives_the_first_redirect_and_reloaded_guard(bool $remember): void
    {
        $user = TestData::user();
        $response = $this->browserRequest('POST', '/login', ['email' => $user->email, 'password' => 'password', 'remember' => $remember]);
        $this->assertSame(302, $response->getStatusCode());
        $marker = $this->app['session']->driver()->get('password_hash_web');
        $this->assertSame(Auth::guard('web')->hashPasswordForCookie($user->password), $marker);
        $this->assertSame(200, $this->browserRequest('GET', '/profile')->getStatusCode());
        $this->assertSame($user->id, Auth::id());
    }

    public static function rememberOptions(): array
    {
        return ['normal login' => [false], 'remembered login' => [true]];
    }

    public function test_legacy_file_session_without_a_marker_still_requires_a_new_login(): void
    {
        $user = TestData::user();
        $this->browserRequest('POST', '/login', ['email' => $user->email, 'password' => 'password']);
        $store = $this->app['session']->driver();
        $store->forget('password_hash_web');
        $store->save();
        $response = $this->browserRequest('GET', '/profile');
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/login', $response->headers->get('Location'));
        $this->assertGuest();
    }

    public function test_remember_cookie_reauthentication_initializes_a_new_file_session(): void
    {
        $user = TestData::user();
        $this->browserRequest('POST', '/login', ['email' => $user->email, 'password' => 'password', 'remember' => true]);
        unset($this->cookies[config('session.cookie')]);
        $this->assertSame(200, $this->browserRequest('GET', '/profile')->getStatusCode());
        $this->assertSame($user->id, Auth::id());
        $this->assertNotEmpty($this->app['session']->driver()->get('password_hash_web'));
    }

    public function test_changed_password_rejects_an_established_file_session(): void
    {
        $user = TestData::user();
        $this->browserRequest('POST', '/login', ['email' => $user->email, 'password' => 'password']);
        $user->update(['password' => 'Changed synthetic password 123!']);
        $this->assertSame(302, $this->browserRequest('GET', '/profile')->getStatusCode());
        $this->assertGuest();
    }
}
