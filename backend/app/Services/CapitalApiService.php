<?php

namespace App\Services;

use App\Models\ConfigEntry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CapitalApiService
{
    private ?string $apiKey;

    private ?string $email;

    private ?string $password;

    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey = ConfigEntry::find('capital_api_key')?->value;
        $this->email = ConfigEntry::find('capital_email')?->value;
        $this->password = ConfigEntry::find('capital_password')?->value;
        $this->baseUrl = rtrim(ConfigEntry::find('capital_url')?->value ?: 'https://api-capital.backend-capital.com', '/');
    }

    public function getPositions(): array
    {
        return $this->request('get', '/api/v1/positions');
    }

    public function getActivity(int $lastPeriod = 86400): array
    {
        return $this->request('get', '/api/v1/history/activity', [
            'lastPeriod' => $lastPeriod,
            'detailed' => 'true',
        ]);
    }

    public function getTransactions(int $lastPeriod = 86400): array
    {
        return $this->request('get', '/api/v1/history/transactions', [
            'lastPeriod' => $lastPeriod,
        ]);
    }

    private function login(): array
    {
        return Cache::remember('capital_session', 480, function () {
            $response = Http::withHeaders([
                'X-CAP-API-KEY' => $this->apiKey,
            ])->post("{$this->baseUrl}/api/v1/session", [
                'identifier' => $this->email,
                'password' => $this->password,
            ]);

            if ($response->failed() || $response->json('errorCode')) {
                throw new RuntimeException('Capital.com Login fehlgeschlagen: '.$response->body());
            }

            return [
                'cst' => $response->header('CST'),
                'token' => $response->header('X-SECURITY-TOKEN'),
            ];
        });
    }

    private function request(string $method, string $uri, array $query = []): array
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('Capital.com Zugangsdaten fehlen — bitte im Settings-Tab eintragen');
        }

        $session = $this->login();
        $response = $this->send($method, $uri, $query, $session);

        if ($this->hasSessionError($response)) {
            Cache::forget('capital_session');
            $session = $this->login();
            $response = $this->send($method, $uri, $query, $session);

            if ($this->hasSessionError($response)) {
                throw new RuntimeException('Capital.com API Fehler: '.$response->body());
            }
        }

        return $response->json();
    }

    private function send(string $method, string $uri, array $query, array $session)
    {
        return Http::withHeaders([
            'X-CAP-API-KEY' => $this->apiKey,
            'CST' => $session['cst'],
            'X-SECURITY-TOKEN' => $session['token'],
        ])->{$method}("{$this->baseUrl}{$uri}", $query);
    }

    private function hasSessionError($response): bool
    {
        return $response->failed() || $response->json('errorCode') !== null;
    }
}
