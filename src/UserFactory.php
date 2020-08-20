<?php

namespace GrantHolle\PowerSchool\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

class UserFactory
{
    public static function mapAttributes(array $configAttributes, Collection $data): array
    {
        return collect($configAttributes)
            ->mapWithKeys(function ($modelKey, $dataKey) use ($data) {
                return [$modelKey => $data->get($dataKey)];
            })
            ->toArray();
    }

    public static function getUserFromOpenId(Collection $data, array $defaultAttributes = []): Authenticatable
    {
        $userType = strtolower($data->get('usertype'));
        $config = config("powerschool-auth.{$userType}");
        $attributes = self::mapAttributes($config['identifying_attributes'], $data);
        $model = $config['model'];

        $user = $model::firstOrNew($attributes)
            ->fill($defaultAttributes);

        if (
            isset($config['attributes']) &&
            !empty($config['attributes'])
        ) {
            $user->fill(self::mapAttributes($config['attributes'], $data));
        }

        $user->save();

        return $user;
    }
}
