<?php

namespace GrantHolle\PowerSchool\Auth\Traits;

use Firebase\JWT\JWT;
use GrantHolle\PowerSchool\Auth\Exceptions\OidcException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Arr;
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

    protected function getRedirectUrl()
    {
        return url('/auth/powerschool/oidc');
    }

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
            ->withQueryParameter('nonce', $nonce)
            ->withQueryParameter('_persona', Arr::first($request->only(['persona', '_persona'])) ?? '');

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

        $data = Configuration::forUnsecuredSigner()
            ->parser()
            ->parse($response['id_token'])
            ->claims();

        if (session()->pull('ps_oidc_nonce') !== $data->get('nonce')) {
            throw new OidcException('Invalid nonce. Please try logging in again.');
        }

        dd($data->all());
    }
}
