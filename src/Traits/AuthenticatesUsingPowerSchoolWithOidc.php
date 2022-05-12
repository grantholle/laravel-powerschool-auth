<?php

namespace GrantHolle\PowerSchool\Auth\Traits;

use GrantHolle\PowerSchool\Auth\Exceptions\OidcException;
use GrantHolle\PowerSchool\Auth\UserFactory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Url\Url;

trait AuthenticatesUsingPowerSchoolWithOidc
{
    protected function getPowerSchoolUrl(): string
    {
        return config('powerschool-auth.server_address');
    }

    protected function getClientId(): string
    {
        return config('powerschool-auth.client_id');
    }

    public function getClientSecret(): string
    {
        return config('powerschool-auth.client_secret');
    }

    protected function getOidcConfiguration(string $key = null)
    {
        $response = Http::baseUrl($this->getPowerSchoolUrl())
            ->get('/oauth2/.well-known/openid-configuration')
            ->json();

        if (is_null($key)) {
            return $response;
        }

        return $response[$key] ?? null;
    }

    /**
     * This must match the `redirect-uri` in your plugin.xml
     */
    protected function getRedirectUrl(): string
    {
        return url('/auth/powerschool/oidc');
    }

    protected function getRedirectToRoute(string $userType): string
    {
        $config = config("powerschool-auth.{$userType}");

        return isset($config['redirectTo']) && !empty($config['redirectTo'])
            ? $config['redirectTo']
            : '/home';
    }

    /**
     * Whether to have an extended authenticated session
     *
     * @return bool
     */
    protected function remember(): bool
    {
        return false;
    }

    /**
     * The scope that this will request from PowerSchool.
     * By default it requests all data for the user.
     *
     * @param array $configuration
     * @return array
     */
    protected function getScope(array $configuration): array
    {
        return $configuration['scopes_supported'];
    }

    /**
     * Prepares the authentication request to PowerSchool
     *
     * @param Request $request
     * @return \Illuminate\Contracts\Foundation\Application|RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function authenticate(Request $request)
    {
        $configuration = $this->getOidcConfiguration();
        $nonce = Str::random();
        session()->put('ps_oidc_nonce', $nonce);

        $url = Url::fromString($configuration['authorization_endpoint'])
            ->withQueryParameter('response_type', 'code')
            ->withQueryParameter('client_id', $this->getClientId())
            ->withQueryParameter('redirect_uri', $this->getRedirectUrl())
            ->withQueryParameter('scope', implode(' ', $this->getScope($configuration)))
            ->withQueryParameter('state', $request->session()->token())
            ->withQueryParameter('nonce', $nonce);

        $persona = $request->only(['persona', '_persona']);

        if (!empty($persona)) {
            $url = $url->withQueryParameter('_persona', Arr::first($persona));
        }

        return redirect($url);
    }

    /**
     * Receives the response from PowerSchool
     * that includes the JWT token and claims
     */
    public function login(Request $request): RedirectResponse|Response
    {
        if (!hash_equals($request->session()->token(), $request->input('state', ''))) {
            throw new TokenMismatchException('Invalid state. Please try logging in again.');
        }

        $params = [
            'grant_type' => 'authorization_code',
            'code' => $request->input('code'),
            'redirect_uri' => $this->getRedirectUrl(),
            'client_id' => $this->getClientId(),
            'client_secret' => $this->getClientSecret(),
        ];

        $response = Http::asForm()
            ->post($this->getOidcConfiguration('token_endpoint'), $params)
            ->json();

        if (isset($response['error'])) {
            throw new OidcException("{$response['error']}: {$response['error_description']}");
        }

        // This one-liner parses the jwt token without a library...
        $dataSet = json_decode(base64_decode(str_replace('_', '/', str_replace('-', '+', explode('.', $response['id_token'])[1]))));
        $data = collect($dataSet);

        if (session()->pull('ps_oidc_nonce') !== $data->get('nonce')) {
            throw new OidcException('Invalid nonce. Please try logging in again.');
        }

        $config = config('powerschool-auth');
        $userType = UserFactory::getUserType($data);

        if (
            !isset($config[$userType]['allowed']) ||
            !$config[$userType]['allowed']
        ) {
            return $this->sendNotAllowedResponse();
        }

        $user = UserFactory::getUser($data, $this->getDefaultAttributes($request, $data));

        auth()->guard(config("powerschool-auth.{$userType}.guard"))
            ->login($user, $this->remember());

        return $this->sendLoginResponse($request, $user, $data);
    }

    /**
     * Send the response after the user was authenticated.
     */
    protected function sendLoginResponse(Request $request, Authenticatable $user, Collection $data): RedirectResponse
    {
        $request->session()->regenerate();
        $userType = UserFactory::getUserType($data);

        return $this->authenticated($request, $user, $data)
            ?: redirect()->intended($this->getRedirectToRoute($userType));
    }

    /**
     * If a user type has `'allowed' => false` in the config,
     * this is the response to send for that user's attempt.
     */
    protected function sendNotAllowedResponse(): Response
    {
        return response('Forbidden', 403);
    }

    /**
     * Gets the default attributes to be added for this user
     */
    protected function getDefaultAttributes(Request $request, Collection $data): array
    {
        return [];
    }

    /**
     * The user has been authenticated.
     */
    protected function authenticated(Request $request, Authenticatable $user, Collection $data): mixed
    {
        //
    }
}
