<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginProxyRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_redirect_preserves_local_forwarded_host_and_port(): void
    {
        $user = User::factory()->create([
            'email' => 'local-login@example.test',
            'password' => Hash::make('LongPassword123'),
            'is_active' => true,
        ]);

        $this->withServerVariables([
            'REMOTE_ADDR' => '172.30.0.3',
            'HTTP_HOST' => 'localhost:8080',
            'HTTP_X_FORWARDED_HOST' => 'localhost:8080',
            'HTTP_X_FORWARDED_PROTO' => 'http',
            'HTTP_X_FORWARDED_PORT' => '8080',
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
            'REMOTE_ADDR' => '172.30.0.3',
            'HTTP_HOST' => 'internal-nginx',
            'HTTP_X_FORWARDED_HOST' => 'questionnaires.example.test',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_PORT' => '443',
        ])->post('/login', [
            'email' => $user->email,
            'password' => 'LongPassword123',
        ])->assertRedirect('https://questionnaires.example.test');
    }

    public function test_login_redirect_trusts_https_from_the_docker_edge_proxy_subnet(): void
    {
        $user = User::factory()->create([
            'email' => 'edge-login@example.test',
            'password' => Hash::make('LongPassword123'),
            'is_active' => true,
        ]);

        $this->withServerVariables([
            'REMOTE_ADDR' => '172.30.0.3',
            'HTTP_HOST' => 'nginx',
            'HTTP_X_FORWARDED_HOST' => 'quick-demo.trycloudflare.com',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_PORT' => '443',
        ])->post('/login', [
            'email' => $user->email,
            'password' => 'LongPassword123',
        ])->assertRedirect('https://quick-demo.trycloudflare.com');
    }
}
