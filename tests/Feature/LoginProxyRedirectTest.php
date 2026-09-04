<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginProxyRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $previousGetenv = getenv('TRUSTED_PROXIES');
        $hadEnv = array_key_exists('TRUSTED_PROXIES', $_ENV);
        $previousEnv = $_ENV['TRUSTED_PROXIES'] ?? null;
        $hadServer = array_key_exists('TRUSTED_PROXIES', $_SERVER);
        $previousServer = $_SERVER['TRUSTED_PROXIES'] ?? null;

        putenv('TRUSTED_PROXIES=127.0.0.1');
        $_ENV['TRUSTED_PROXIES'] = '127.0.0.1';
        $_SERVER['TRUSTED_PROXIES'] = '127.0.0.1';

        $app = parent::createApplication();

        if ($previousGetenv === false) {
            putenv('TRUSTED_PROXIES');
        } else {
            putenv("TRUSTED_PROXIES=$previousGetenv");
        }
        if ($hadEnv) {
            $_ENV['TRUSTED_PROXIES'] = $previousEnv;
        } else {
            unset($_ENV['TRUSTED_PROXIES']);
        }
        if ($hadServer) {
            $_SERVER['TRUSTED_PROXIES'] = $previousServer;
        } else {
            unset($_SERVER['TRUSTED_PROXIES']);
        }

        return $app;
    }

    public function test_login_redirect_preserves_local_forwarded_host_and_port(): void
    {
        $user = User::factory()->create([
            'email' => 'local-login@example.test',
            'password' => Hash::make('LongPassword123'),
            'is_active' => true,
        ]);

        $this->withServerVariables([
            'HTTP_HOST' => 'localhost:8080',
            'HTTP_X_FORWARDED_HOST' => 'localhost:8080',
            'HTTP_X_FORWARDED_PROTO' => 'http',
        ])->post('/login', [
            'email' => $user->email,
            'password' => 'LongPassword123',
        ])->assertRedirect('http://localhost:8080');
    }

    public function test_login_redirect_preserves_https_forwarded_host(): void
    {
        $user = User::factory()->create([
            'email' => 'https-login@example.test',
            'password' => Hash::make('LongPassword123'),
            'is_active' => true,
        ]);

        $this->withServerVariables([
            'HTTP_HOST' => 'internal-nginx',
            'HTTP_X_FORWARDED_HOST' => 'questionnaires.example.test',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->post('/login', [
            'email' => $user->email,
            'password' => 'LongPassword123',
        ])->assertRedirect('https://questionnaires.example.test');
    }
}
