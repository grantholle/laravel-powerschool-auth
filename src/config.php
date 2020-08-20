<?php

return [

    /*
    |--------------------------------------------------------------------------
    | User Configuration
    |--------------------------------------------------------------------------
    |
    | When authenticating users from PowerSchool, there are essentially
    | three different types of users: staff, guardian, and student.
    | Configure their model class, guard, and attributes to sync.
    |
    | If `allowed` is true, that user type may authenticate.
    | See the readme for more examples on user type authorization.
    |
    | Set the `model` value to be the class of the appropriate model
    | for the given user type.
    |
    | Attributes are configured by setting the key's value to be the attribute
    | that is received from PowerSchool and the value's value the attribute
    | that is used in your actual model. To forgo syncing, set `attributes`
    | to be an empty array.
    |
    | The `guard` is the name of the guard to authenticate.
    |
    | The `identifying_attributes` map the OpenID attributes and column
    | on the given model by which to look up users in the database.
    | There are some caveats discussed in the readme. This assumes that
    | the values are actually present in the appropriate database.
    | Generally, these are the keys you would want to use
    | openid_claimed_id, email, and/or ref.
    |
    | Lastly, there is a `redirectTo` key that the user will be redirected
    | to after successfully authenticating.
    |
    */

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
            'openid_claimed_id' => 'openid_identity',
        ],
        'redirectTo' => '',
    ],

    'guardian' => [
        'allowed' => true,
        'model' => \App\User::class,
        'attributes' => [
            'firstName' => 'first_name',
            'lastName' => 'last_name',
            'email' => 'email',
        ],
        'guard' => 'web',
        'identifying_attributes' => [
            'openid_claimed_id' => 'openid_identity',
        ],
        'redirectTo' => '',
    ],

    'student' => [
        'allowed' => true,
        'model' => \App\User::class,
        'attributes' => [
            'firstName' => 'first_name',
            'lastName' => 'last_name',
            'email' => 'email',
        ],
        'guard' => 'web',
        'identifying_attributes' => [
            'openid_claimed_id' => 'openid_identity',
        ],
        'redirectTo' => '',
    ],

];
