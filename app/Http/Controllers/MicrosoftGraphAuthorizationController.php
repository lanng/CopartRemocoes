<?php

namespace App\Http\Controllers;

use App\Models\MicrosoftGraphConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MicrosoftGraphAuthorizationController extends Controller
{
    public function connect(Request $request): RedirectResponse
    {
        $state = Str::random(40);
        $request->session()->put('microsoft_graph_oauth_state', $state);

        $query = http_build_query([
            'client_id' => config('services.microsoft_graph.client_id'),
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            'response_mode' => 'query',
            'scope' => config('services.microsoft_graph.scopes'),
            'state' => $state,
        ]);

        return redirect()->away($this->baseUrl('/oauth2/v2.0/authorize').'?'.$query);
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless(
            $request->string('state')->toString() !== '' &&
            hash_equals((string) $request->session()->pull('microsoft_graph_oauth_state'), $request->string('state')->toString()),
            403,
        );

        $tokenResponse = Http::asForm()->post($this->baseUrl('/oauth2/v2.0/token'), [
            'client_id' => config('services.microsoft_graph.client_id'),
            'client_secret' => config('services.microsoft_graph.client_secret'),
            'grant_type' => 'authorization_code',
            'code' => $request->string('code')->toString(),
            'redirect_uri' => $this->redirectUri(),
            'scope' => config('services.microsoft_graph.scopes'),
        ])->throw()->json();
        $profile = Http::withToken($tokenResponse['access_token'])->get('https://graph.microsoft.com/v1.0/me')->throw()->json();

        $connection = MicrosoftGraphConnection::query()->first();
        $connection ??= new MicrosoftGraphConnection(['activated_at' => now()]);
        $connection->fill([
            'account_email' => $profile['mail'] ?? $profile['userPrincipalName'],
            'access_token' => $tokenResponse['access_token'],
            'refresh_token' => $tokenResponse['refresh_token'],
            'expires_at' => now()->addSeconds($tokenResponse['expires_in'] ?? 3600),
            'is_active' => true,
            'last_error' => null,
        ])->save();

        return redirect('/admin');
    }

    private function baseUrl(string $path): string
    {
        return 'https://login.microsoftonline.com/'.config('services.microsoft_graph.tenant').$path;
    }

    private function redirectUri(): string
    {
        return config('services.microsoft_graph.redirect_uri');
    }
}
