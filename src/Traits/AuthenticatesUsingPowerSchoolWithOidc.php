<?php

namespace GrantHolle\PowerSchool\Auth\Traits;

use GrantHolle\PowerSchool\Auth\Exceptions\OidcException;
use GrantHolle\PowerSchool\Auth\UserFactory;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Lcobucci\JWT\Configuration;
use Spatie\Url\Url;

trait AuthenticatesUsingPowerSchoolWithOidc
{
    protected function getOidcConfiguration(string $key = null)
    {
        $response = Http::get(config('powerschool-auth.server_address') . '/oauth2/.well-known/openid-configuration')
            ->json();

        if (is_null($key)) {
            return $response;
        }

        return $response[$key] ?? null;
    }

    /**
     * This must match the `redirect-uri` in your plugin.xml
     *
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\UrlGenerator|string
     */
    protected function getRedirectUrl()
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

//    public function authenticate(Request $request)
//    {
//        $configuration = $this->getOidcConfiguration();
//
//        $oidc = new OpenIDConnectClient(
//            config('powerschool-auth.server_address'),
//            config('powerschool-auth.client_id'),
//            config('powerschool-auth.client_secret')
//        );
//
//        foreach ($this->getScope($configuration) as $scope) {
//            $oidc->addScope($scope);
//        }
//
//        $oidc->providerConfigParam($configuration);
//        $oidc->setRedirectURL($this->getRedirectUrl());
//        $oidc->authenticate();
//        $token = $oidc->requestResourceOwnerToken(true);
//    }

    public function authenticate(Request $request)
    {
        $configuration = $this->getOidcConfiguration();
        $nonce = Str::random();
        session()->put('ps_oidc_nonce', $nonce);

        $url = Url::fromString($configuration['authorization_endpoint'])
            ->withQueryParameter('response_type', 'code')
            ->withQueryParameter('client_id', config('powerschool-auth.client_id'))
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

    public function login(Request $request)
    {
        if (!hash_equals($request->session()->token(), $request->input('state'))) {
            throw new TokenMismatchException('Invalid state. Please try logging in again.');
        }

        $params = [
            'grant_type' => 'authorization_code',
            'code' => $request->input('code'),
            'redirect_uri' => $this->getRedirectUrl(),
            'client_id' => config('powerschool-auth.client_id'),
            'client_secret' => config('powerschool-auth.client_secret'),
        ];

        $response = Http::withBody(http_build_query($params), 'application/x-www-form-urlencoded')
            ->post($this->getOidcConfiguration('token_endpoint'))
            ->json();

        if (isset($response['error'])) {
            throw new OidcException("{$response['error']}: {$response['error_description']}");
        }

        $dataSet = Configuration::forUnsecuredSigner()
            ->parser()
            ->parse($response['id_token'])
            ->claims();
        $data = collect($dataSet->all());

        if (session()->pull('ps_oidc_nonce') !== $data->get('nonce')) {
            throw new OidcException('Invalid nonce. Please try logging in again.');
        }

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
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Support\Collection  $data
     * @return \Illuminate\Http\RedirectResponse
     */
    protected function sendLoginResponse(Request $request, $user, Collection $data)
    {
        $request->session()->regenerate();
        $userType = UserFactory::getUserType($data);

        return $this->authenticated($request, $user, $data)
            ?: redirect()->intended($this->getRedirectToRoute($userType));
    }

    /**
     * If a user type has `'allowed' => false` in the config,
     * this is the response to send for that user's attempt.
     *
     * @return \Illuminate\Http\Response
     */
    protected function sendNotAllowedResponse()
    {
        return response('Forbidden', 403);
    }

    /**
     * Gets the default attributes to be added for this user
     *
     * @param Request $request
     * @param Collection $data
     * @return array
     */
    protected function getDefaultAttributes(Request $request, Collection $data): array
    {
        return [];
    }

    /**
     * The user has been authenticated.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  mixed  $user
     * @param  \Illuminate\Support\Collection  $data
     * @return mixed
     */
    protected function authenticated(Request $request, $user, Collection $data)
    {
        //
    }
}
