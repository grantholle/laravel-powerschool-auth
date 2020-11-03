<?php

namespace GrantHolle\PowerSchool\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

class UserFactory
{
    public static function mapAttributes(array $configAttributes, Collection $data, array $config): array
    {
        $transformers = $config['attribute_transformers'] ?? [];

        return collect($configAttributes)
            ->mapWithKeys(function ($modelKey, $dataKey) use ($data, $transformers) {
                $value = $data->get($dataKey);
                $transformer = $transformers[$dataKey] ?? null;

                if ($transformer) {
                    $invoker = new $transformer;
                    $value = $invoker($value);
                }

                return [$modelKey => $value];
            })
            ->toArray();
    }

    public static function getUserFromOpenId(Collection $data, array $defaultAttributes = []): Authenticatable
    {
        $userType = strtolower($data->get('usertype'));
        $config = config("powerschool-auth.{$userType}");

        $attributes = self::mapAttributes($config['identifying_attributes'], $data, $config);

        $model = $config['model'];

        $user = $model::firstOrNew($attributes)
            ->fill($defaultAttributes);

        if (
            isset($config['attributes']) &&
            !empty($config['attributes'])
        ) {
            $user->fill(self::mapAttributes($config['attributes'], $data, $config));
        }

        $user->save();

        return $user;
    }
}
