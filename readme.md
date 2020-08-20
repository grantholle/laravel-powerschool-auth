# Laravel PowerSchool Auth

Use PowerSchool as an identity provider for your Laravel app.

## Installation

```
composer require grantholle/laravel-powerschool-auth
```

## Configuration

First publish the config file, `config/powerschool-auth.php`.

```
php artisan vendor:publish --provider="GrantHolle\PowerSchool\Auth\PowerSchoolAuthServiceProvider"
```

The configuration is separated by the different user types, `staff`, `guardian`, and `student`.

```php
return [
    'staff' => [
        // Setting to false will prevent this user type from authenticating
        'allowed' => true,
        
        // This is the model to use for a given type
        // Theoretically you could have different models
        // for the different user types 
        'model' => \App\User::class,
        
        // These attributes will be synced to your model
        // PS attribute => your app attribute 
        'attributes' => [
            'firstName' => 'first_name',
            'lastName' => 'last_name',
            'email' => 'email',
        ],
    
        // The guard used to authenticate your user
        'guard' => 'web',

        // These is the properties used to look up a user's record
        // OpenID identifier so they can be identified
        // You will need to have this column already added to
        // the given model's migration/schema.
        'identifying_attributes' => [
            'openid_claimed_id' => 'openid_identity',
        ],

        // The path to be redirected to once they are authenticated
        'redirectTo' => '',
    ],
       
    // 'guardian' => [ ...
    // 'student' => [ ...
];
```

## Usage

This assumes you have a plugin installed on your instance of PowerSchool with something similar to the following in your `plugin.xml` file:

```xml
<openid host="yourapp.com" port="443">
  <links>
    <link display-text="Click here to log in" path="/auth/powerschool/openid" title="An app that uses SSO.">
      <ui_contexts>
        <ui_context id="admin.header"/>
        <ui_context id="admin.left_nav"/>
        <ui_context id="teacher.header"/>
        <ui_context id="guardian.header"/>
        <ui_context id="student.header"/>
      </ui_contexts>
    </link>
  </links>
</openid>
```

Installing that plugin will inject links in the application popout menu for all user types. We need to create a controller that handles authentication with PowerSchool.

```
php artisan make:controller Auth\PowerSchoolOpenIdLoginController
```

After the controller is generated, we need to add the trait that handles all the authentication boilerplate.

```php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use GrantHolle\PowerSchool\Auth\Traits\AuthenticatesUsingPowerSchoolWithOpenId;

class PowerSchoolOpenIdLoginController extends Controller
{
    use AuthenticatesUsingPowerSchoolWithOpenId;
}
```

Now let's add the applicable routes to your `web.php` file:

```php
// These paths can be whatever you want; the key thing is that they path for `authenticate`
// matches what you've configured in your plugin.xml file for the `path` attribute
Route::get('/auth/powerschool/openid', 'Auth\PowerSchoolOpenIdLoginController@authenticate');
Route::get('/auth/powerschool/openid/verify', 'Auth\PowerSchoolOpenIdLoginController@login')
    ->name('openid.verify');
```

By default, the verification route back to PowerSchool is expected to be `/auth/powerschool/openid/verify`, but that can be changed by overwriting `getVerifyRoute()` as discussed below.

Once the user opens your SSO link in PowerSchool, there will be the OpenID exchange and data will be given to your app from PowerSchool. There are several "hooks" where you can change the behavior without having to modify the underlying behavior itself. Here is a snippet of the functions and their default behavior you can overwrite in your controller.

```php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use GrantHolle\PowerSchool\Auth\Traits\AuthenticatesUsingPowerSchoolWithOpenId;

class PowerSchoolOpenIdLoginController extends Controller
{
    use AuthenticatesUsingPowerSchoolWithOpenId;
    
     /**
     * This will get the route to the `login` function after
     * the authentication request has been sent to PowerSchool
     * 
     * @return string
     */
    protected function getVerifyRoute(): string
    {
        return url('/auth/powerschool/openid/verify');
    }

    /**
     * This will get the route that should be used after successful authentication.
     * The user type (staff/guardian/student) is sent as the parameter.
     *
     * @param string $userType 
     * @return string
     */
    protected function getRedirectToRoute(string $userType): string
    {
        $config = config("powerschool-auth.{$userType}");

        return isset($config['redirectTo']) && !empty($config['redirectTo'])
            ? $config['redirectTo']
            : '/home';
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
     * Gets the default attributes to be fill for the user
     * that wouldn't be included in the data exchange with PowerSchool
     * or that need some custom logic that can't be configured.
     * The attributes set in the config's `attributes` key will overwrite
     * these if they are the same. `$data` in this context is the data
     * received from PowerSchool. For example, you may want to store
     * the dcid of the user being authenticated.
     *
     * @param \Illuminate\Http\Response $request
     * @param \Illuminate\Support\Collection $data
     * @return array
     */
    protected function getDefaultAttributes($request, $data): array
    {
        return [];
    }

    /**
     * The user has been authenticated. 
     * You can return a custom response here, perform custom actions, etc.
     * Otherwise, you can change the route in `getRedirectToRoute()`.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  mixed  $user
     * @param  \Illuminate\Support\Collection  $data
     * @return mixed
     */
    protected function authenticated($request, $user, $data)
    {
        //
    }
}
```

## Caveats

Right now there is only an OpenID 2.0 implementation for SSO. I would like to have SAML integration added, but it's considerably more complicated to include. There is also is the question, why add it if OpenID works? It's more configurable, but also adds a lot more complexity. I'm willing to add it once I have the bandwidth and will certainly entertain a pull request to include it.

That being said, PowerSchool doesn't support the `<identityAttribute/>` configuration to customize the user's identity attribute. For OpenID, as far as I can tell, it defaults to `{url}/oid/{usertype}/{username}`. In our company we have experienced weird behavior if the username contains weird characters. For example, it's valid in PowerSchool to have Chinese/Korean usernames. The identifier that gets sent is just encoded spaces, i.e. `{url}/oid/guardian/%20%20%20`. For some reason email addresses work ok, thankfully.

This also means that if a user's username changes who has already authenticated in your application, they will be authenticated as a new user because their OpenID identifier has also changed. For this reason you may want to configure a different attribute, such as `email`, to be used as the identifying attribute. It depends on whether you expect emails or usernames to change more often.

```php
[
    'staff' => [
        'allowed' => true,
        'model' => \App\User::class,
        'attributes' => [
            'firstName' => 'first_name',
            'lastName' => 'last_name',
            'email' => 'email',
        ],
        'guard' => 'web',
        'identifying_attributes' => [
            'email' => 'email',
        ],
        'redirectTo' => '',
    ],
];
```

## License

[MIT](LICENSE.md)
