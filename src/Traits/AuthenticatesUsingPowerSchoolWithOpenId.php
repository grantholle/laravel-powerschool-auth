<?php

namespace GrantHolle\PowerSchool\Auth\Traits;

use GrantHolle\PowerSchool\Auth\Exceptions\ConfigurationException;
use GrantHolle\PowerSchool\Auth\UserFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Pear\Net\Url2;
use Pear\OpenId\Extensions\AX;
use Pear\OpenId\Extensions\OpenIdExtension;
use Pear\OpenId\OpenIdMessage;
use Pear\OpenId\RelyingParty;

trait AuthenticatesUsingPowerSchoolWithOpenId
{
    protected function getVerifyRoute(): string
    {
        return url('/auth/powerschool/openid/verify');
    }

    protected function getRedirectToRoute(string $userType): string
    {
        $config = config("powerschool-auth.{$userType}");

        return isset($config['redirectTo']) && !empty($config['redirectTo'])
            ? $config['redirectTo']
            : '/home';
    }

    /**
     * Receives the SSO request and requests data from PS
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\RedirectResponse
     * @throws \Exception
     */
    public function authenticate(Request $request)
    {
        // Set up the relying party
        $relyingParty = new RelyingParty(
            $this->getVerifyRoute(),
            $request->getSchemeAndHttpHost(),
            $request->input('openid_identifier')
        );
        $relyingParty->disableAssociations();
        $authRequest = $relyingParty->prepare();

        // Add all the extension fields for the request
        $ax = new AX(OpenIdExtension::REQUEST);

        $ax->set('type.studentids', 'http://powerschool.com/entity/studentids');
        $ax->set('type.dcid', 'http://powerschool.com/entity/id');
        $ax->set('type.usertype', 'http://powerschool.com/entity/type');
        $ax->set('type.ref', 'http://powerschool.com/entity/ref');
        $ax->set('type.email', 'http://powerschool.com/entity/email');
        $ax->set('type.firstName', 'http://powerschool.com/entity/firstName');
        $ax->set('type.lastName', 'http://powerschool.com/entity/lastName');
        $ax->set('type.districtName', 'http://powerschool.com/entity/districtName');
        $ax->set('type.districtCustomerNumber', 'http://powerschool.com/entity/districtCustomerNumber');
        $ax->set('type.districtState', 'http://powerschool.com/entity/districtState');
        $ax->set('type.districtCountry', 'http://powerschool.com/entity/districtCountry');
        $ax->set('type.schoolID', 'http://powerschool.com/entity/schoolID');
        $ax->set('type.usersDCID', 'http://powerschool.com/entity/usersDCID');
        $ax->set('type.teacherNumber', 'http://powerschool.com/entity/teacherNumber');
        $ax->set('type.adminSchools', 'http://powerschool.com/entity/adminSchools');
        $ax->set('type.teacherSchools', 'http://powerschool.com/entity/teacherSchools');
        $ax->set('mode', 'fetch_request');
        $ax->set('required', 'studentids,dcid,usertype,ref,email,firstName,lastName,districtName,districtCustomerNumber,districtState,districtCountry,schoolID,usersDCID,teacherNumber,adminSchools,teacherSchools');

        $authRequest->addExtension($ax);

        return redirect($authRequest->getAuthorizeURL());
    }

    /**
     * Receives the data after successful authentication
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     * @throws ConfigurationException
     * @throws \Pear\OpenId\Exceptions\OpenIdAssociationException
     * @throws \Pear\OpenId\Exceptions\OpenIdException
     * @throws \Pear\OpenId\Exceptions\OpenIdMessageException
     * @throws \Pear\OpenId\Exceptions\StoreException
     */
    public function login(Request $request)
    {
        if (!$this->authVerified($request)) {
            abort(403);
        }

        $data = $this->normalizeExchangedData($request);
        $userType = strtolower($data->get('usertype'));

        $config = config('powerschool-auth');

        // If there is nothing configured for the user type
        // Don't authenticate and continue
        if (!isset($config[$userType])) {
            throw new ConfigurationException("User type '{$userType}' is not configured properly");
        }

        if (
            !isset($config[$userType]['allowed']) ||
            !$config[$userType]['allowed']
        ) {
            return $this->sendNotAllowedResponse();
        }

        $user = UserFactory::getUserFromOpenId($data, $this->getDefaultAttributes($request, $data));

        auth()->guard($config[$userType]['guard'])->login($user);

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

        return $this->authenticated($request, $user, $data)
            ?: redirect()->intended($this->getRedirectToRoute($data->get('usertype')));
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

    /**
     * This will verify the data exchange to confirm real authentication
     *
     * @param Request $request
     * @return bool
     * @throws \Pear\OpenId\Exceptions\OpenIdAssociationException
     * @throws \Pear\OpenId\Exceptions\OpenIdException
     * @throws \Pear\OpenId\Exceptions\OpenIdMessageException
     * @throws \Pear\OpenId\Exceptions\StoreException
     */
    protected function authVerified(Request $request): bool
    {
        $relyingParty = new RelyingParty(
            $this->getVerifyRoute(),
            $request->getSchemeAndHttpHost(),
            $request->input('openid_identity')
        );

        $relyingParty->disableAssociations();
        $queryString = $request->server('QUERY_STRING');

        $message = new OpenIdMessage($queryString, OpenIdMessage::FORMAT_HTTP);

        $url = $this->getVerifyRoute() . '?' . $queryString;

        return ($relyingParty->verify(new Url2($url), $message))->success();
    }

    /**
     * Normalizes the data that was received from PowerSchool
     *
     * @param Request $request
     * @return Collection
     */
    protected function normalizeExchangedData(Request $request): Collection
    {
        $allData = $request->all();

        return collect(array_keys($allData))
            ->reduce(function (Collection $collection, string $key) use ($allData) {
                if (!Str::contains($key, ['_value_', 'openid_claimed_id'])) {
                    return $collection;
                }

                $niceKey = Str::contains($key, '_value_')
                    ? substr($key, 18)
                    : 'openid_claimed_id';

                // Admin and teacher schools need to be decoded
                $value = in_array($niceKey, ['adminSchools', 'teacherSchools'])
                    ? json_decode($allData[$key])
                    : $allData[$key];

                $collection->put($niceKey, $value);

                return $collection;
            }, collect());
    }
}
