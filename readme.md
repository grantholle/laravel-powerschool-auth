# Laravel PowerSchool Auth

Use PowerSchool as an identity provider for your Laravel app, supporting the original OpenID 2.0 implementation, as well as OpenID Connect that was introduced in PowerSchool 20.11.

OpenID 2.0 are links that are within PowerSchool that are sent to your application to perform a data exchange. It can only be performed from PowerSchool to your app. OpenID Connect is a way for users to authenticate against PowerSchool directly from your application and provides a better user experience overall. 

## Installation

```
composer require grantholle/laravel-powerschool-auth
```

## Configuration

First publish the config file, `config/powerschool-auth.php`.

```
php artisan vendor:publish --provider="GrantHolle\PowerSchool\Auth\PowerSchoolAuthServiceProvider"
```

The configuration is separated by the different user types, `staff`, `guardian`, and `student`. The OpenID Connect OAuth flow supports four types (`staff`, `teacher`, `parent`, `student`), but for the sake of flexibility `staff` and `teacher` will be merged into the singular `staff` type. The `parent` OIDC persona will reference the `guardian` key in the config.

```php
return [
    // These are required for OpenID Connect
    'server_address' => env('POWERSCHOOL_ADDRESS'),
    'client_id' => env('POWERSCHOOL_CLIENT_ID'),
    'client_secret' => env('POWERSCHOOL_CLIENT_SECRET'),
    
    // User type configuration
    'staff' => [
        // Setting to false will prevent this user type from authenticating
        'allowed' => true,
        
        // This is the model to use for a given type
        // Theoretically you could have different models
        // for the different user types 
        'model' => \App\User::class,
        
        // These attributes will be synced to your model
        // PS attribute => your app attribute 
        // Put either OpenID implementation in this
        // The app will parse whether the key exists in
        // the response.
        'attributes' => [
            // These attributes are from OpenID 2.0
            'firstName' => 'first_name',
            'lastName' => 'last_name',
            // Shared with 2.0 and Connect
            'email' => 'email',
            // These are OpenID Connect attributes
            'given_name' => 'first_name',
            'family_name' => 'last_name',
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

This assumes you have a plugin installed on your instance of PowerSchool with something similar to the following in your `plugin.xml` file. The 

```xml
<?xml version="1.0" encoding="UTF-8"?>
<plugin xmlns="http://plugin.powerschool.pearson.com" name="My Plugin" version="1.0" description="An example OpenID plugin.">
  <oauth 
    base-url="https://example.com"
    redirect-uri="/auth/powerschool/oidc/verify"
    allow-client-credential="false"
    allow-auth-code="true"
  >
    <allow-persona>staff</allow-persona>
    <allow-persona>teacher</allow-persona>
  </oauth>
  <openid host="example.com" port="443">
    <links>
      <link display-text="Click here to log in" path="/auth/powerschool/openid" title="An app that uses SSO.">
        <ui_contexts>
          <ui_context id="admin.header"/>
          <ui_context id="admin.left_nav"/>
          <ui_context id="teacher.header"/>
          <ui_context id="teacher.pro.apps"/>
        </ui_contexts>
      </link>
    </links>
  </openid>
  <publisher name="Example Publisher">
    <contact email="publisher@example.com" />
  </publisher>
</plugin>
```

Use the [PowerSchool documentation](https://support.powerschool.com/developer/#/page/three-legged-oauth) for more details on tag and attribute meaning. In short, the `oauth` tag supports configuration about an OpenID Connect OAuth flow, while the `openid` tag has details about OpenID 2.0 authentication. Installing that plugin will inject links in the application popout menu for only staff. Now we need to create a controller that handles authentication with PowerSchool.

### OpenID 2.0

First, let's make the OpenID 2.0 authentication controller:

```
php artisan make:controller Auth/PowerSchoolOpenIdLoginController
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
Route::get('/auth/powerschool/openid', [\App\Http\Controllers\Auth\PowerSchoolOpenIdLoginController::class, 'authenticate']);
Route::get('/auth/powerschool/openid/verify', [\App\Http\Controllers\Auth\PowerSchoolOpenIdLoginController::class, 'login'])
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
     * Gets the default attributes to be filled for the user
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

### OpenID Connect

**Note**: This requires a PowerSchool version of 20.11 or newer.

OpenID Connect (OIDC) provides a better experience from your application to use PowerSchool as an Identity Provider. You can send a user from your app to their PowerSchool server to authenticate, then back to your app with some information. For OIDC to work, you must include these keys in your `.env`:

```
POWERSCHOOL_ADDRESS=
POWERSCHOOL_CLIENT_ID=
POWERSCHOOL_CLIENT_SECRET=
```

You will notice that these are shared with the [grantholle/powerschool-api](https://github.com/grantholle/powerschool-api) to avoid duplication.

Next, we'll need to create another controller:

```
php artisan make:controller Auth/PowerSchoolOidcLoginController
```

After the controller is generated, we need to add the trait that handles all the authentication boilerplate.

```php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use GrantHolle\PowerSchool\Auth\Traits\AuthenticatesUsingPowerSchoolWithOidc;

class PowerSchoolOidcLoginController extends Controller
{
    use AuthenticatesUsingPowerSchoolWithOidc;
}
```

Now let's add the applicable routes to your `web.php` file:

```php
// These paths can be whatever you want; the key thing is that they path for the `login` route action to
// match what you've configured in your plugin.xml under `oauth`'s `redirect-uri` attribute file for the `path` attribute
Route::get('/auth/powerschool/oidc', [\App\Http\Controllers\Auth\PowerSchoolOidcLoginController::class, 'authenticate']);
Route::get('/auth/powerschool/oidc/verify', [\App\Http\Controllers\Auth\PowerSchoolOidcLoginController::class, 'login']);

// <oauth 
//   base-url="https://example.com"
//   redirect-uri="/auth/powerschool/oidc/verify" <-- Has to match the route for the `login` action
```

Now when users visit `/auth/powerschool/oidc` in your application, the OAuth flow begins with PowerSchool. If the user is already logged in to PowerSchool, it seamlessly logs in without interruption. If they aren't logged in to PowerSchool, they are brought to a prompt in PowerSchool to log in via admin, teachers, parents, or students, depending on your configuration in your plugin. Be sure to read the docs on the `allow-persona` sub elements in the [docs](https://support.powerschool.com/developer/#/page/three-legged-oauth#PluginConfiguration_OAuthSubElements) for the ability to restrict the user types that can authenticate. You may also pass a `persona` or `_persona` query variable to tell PowerSchool the type of user that is authenticating. This will allow PowerSchool to bypass the user type prompt and go directly to the desired login page. For example:

```html
<a href="/auth/powerschool/oidc?persona=parent">Parent sign in</a>
<!-- <a href="/auth/powerschool/oidc?persona=teacher">Teacher sign in</a> -->
```

The above link will tell PowerSchool that a parent is authenticating, so it can take them directly to the /public login page. You can also allow for longer authenticated sessions by adding a `remember` query variable with a truthy value, such as `1` or `true`. By default, it is `false`. For example:

```html
<a href="/auth/powerschool/oidc?remember=1">Sign in with PowerSchool</a>
```

Just like the OpenID 2.0 "hook" functionality, the OIDC trait has the same ability to modify user attributes and other behavior.

```php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use GrantHolle\PowerSchool\Auth\Traits\AuthenticatesUsingPowerSchoolWithOidc;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class PowerSchoolOidcController extends Controller
{
    use AuthenticatesUsingPowerSchoolWithOidc;
    
    protected function getRedirectUrl()
    {
        return url('/auth/powerschool/oidc');
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
     * By default it requests all scopes for the user.
     *
     * @param array $configuration
     * @return array
     */
    protected function getScope(array $configuration): array
    {
        return $configuration['scopes_supported'];
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
```

## Caveats

Right now there is no SAML integration because it's considerably more complicated to include. There also is the question, why add it if OpenID works? It's more configurable, but also adds a lot more complexity. I'm willing to add it once I have the bandwidth and will certainly entertain a pull request to include it.

That being said, PowerSchool doesn't support the `<identityAttribute/>` configuration to customize the user's identity attribute. For OpenID 2.0, as far as I can tell, it defaults to `{url}/oid/{usertype}/{username}`. In our company we have experienced unwanted behavior if the username contains weird characters. For example, it's valid in PowerSchool to have Chinese/Korean usernames. The identifier that gets sent is just encoded spaces, i.e. `{url}/oid/guardian/%20%20%20`. For some reason email addresses work ok, thankfully.

This also means that if a user's username changes who has already authenticated in your application, they will be authenticated as a new user because their OpenID identifier has also changed. For this reason you may want to configure a different attribute, such as `email`, to be used as the identifying attribute. It depends on whether you expect emails or usernames to change more often.

For OpenID Connect, there is an included `sub` attribute. According to the docs:

> It is the unique and unchanging identifier for the authenticated user, which will never be reused for any future users.

This sounds as if it will always be unique despite the username, as is the problem with OpenID 2.0. However, if there are shared user accounts (staff have a parent account), those accounts would be separate. This is why often times I will use email despite the potential risks. OpenID Connect also excludes student IDs for guardians and admin schools for staff, which is unfortunate. You will have to provide your own PowerQuery for getting that information based on the user.

If you do use email, I would suggest leaving the entry for `email` in `attribute_transformers` that returns the email address in a lowercase format. This way, it doesn't matter how the format it came to our application as it will be normalized in our own database.

The `attribute_transformers` classes only need to have an `__invoke()` magic method that accepts the original value as the argument.

```php
[
    'staff' => [
        'allowed' => true,
        'model' => \App\User::class,
        'attributes' => [
            // PowerSchool attribute => our attribute
            'firstName' => 'first_name',
            'lastName' => 'last_name',
            'email' => 'email',
        ],
        'guard' => 'web',
        'identifying_attributes' => [
            // PowerSchool attribute => our attribute
            'email' => 'email',
        ],
        'attribute_transformers' => [
            // PowerSchool attribute => our class
            'email' => \GrantHolle\PowerSchool\Auth\Transformers\Lowercase::class,
            // See example below
            'lastName' => MyTransformer::class,
        ],
        'redirectTo' => '',
    ],
];
```

```php
class MyTransformer
{
    public function __invoke($value)
    {
        // Manipulate the value somehow
        return 'Mr./Ms. ' . $value;
    }
}
```

### Example data

Here are examples of the attribute exchange from PowerSchool:

OpenID 2.0:

```php
$data = [
    "openid_claimed_id" => "https://my.powerschool.com/oid/admin/jerry.smith",
    "dcid" => "1234",
    "usertype" => "staff",
    "ref" => "https://my.powerschool.com/ws/v1/staff/1234",
    "email" => "jerry.smith@example.com",
    "firstName" => "Jerry",
    "lastName" => "Smith",
    "districtName" => "My District Name",
    "districtCustomerNumber" => "AB1234",
    "districtCountry" => "US",
    "schoolID" => "1",
    "usersDCID" => "1234",
    "teacherNumber" => "111",
    "adminSchools" => [
        0,
        1,
        2,
        3,
        4,
        999999,
    ],
    "teacherSchools" => [
        1,
        2,
    ],
];
```

OpenID Connect:

```php
$data = [
    "sub" => "https://example.com/uri/parent/11111",
    "email_verified" => false,
    "persona" => "parent", // staff/teacher/parent/student
    "kid" => "JWT Signing (Internal)",
    "iss" => "https://example.com/oauth2/",
    "preferred_username" => "username",
    "given_name" => "Given",
    "nonce" => "rPWmHGhGcagFOTiD",
    "ps_uri" => "https://example.com/uri/parent/578000",
    "aud" => [
      0 => "37823263-d6f4-4781-8ccf-5b21ba085ca4",
    ],
    "ps_account_token" => "gi0ubGGVL871AhyevNb6lg==",
    "ps_dcid" => 578000,
    "auth_time" => 1618205362,
    "exp" => DateTimeImmutable {
      date: 2021-04-01 00:00:00.0 +00:00
    },
    "oid2" => "https://example.com/oid/guardian/username",
    "iat" => DateTimeImmutable @1618205362 {
      date: 2021-04-01 00:00:00.0 +00:00
    },
    "family_name" => "Family",
    "jti" => "74220be8-9c3b-4776-8543-157e7a9892a9",
    "email" => "first.last@example.com",
]
```

## License

[MIT](LICENSE.md)
