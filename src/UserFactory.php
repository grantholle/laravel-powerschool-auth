<?php

namespace GrantHolle\PowerSchool\Auth;

use GrantHolle\PowerSchool\Auth\Exceptions\UnableToDetectUserTypeException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

class UserFactory
{
    public static function mapAttributes(array $configAttributes, Collection $data, array $config): array
    {
        $transformers = $config['attribute_transformers'] ?? [];

        return collect($configAttributes)
            ->filter(function ($modelKey, $dataKey) use ($data) {
                return $data->has($dataKey);
            })
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

    public static function getUserType(Collection $data): string
    {
        $type = $data->get('usertype', $data->get('persona'));

        if (!$type) {
            throw new UnableToDetectUserTypeException('Could not detect user type from SSO.');
        }

        $personaMap = [
            'parent' => 'guardian',
            'teacher' => 'staff',
        ];

        return strtolower($personaMap[$type] ?? $type);
    }

    public static function getUser(Collection $data, array $defaultAttributes): Authenticatable
    {
        $userType = static::getUserType($data);
        $config = config("powerschool-auth.{$userType}");
        $attributes = static::mapAttributes($config['identifying_attributes'], $data, $config);

        $model = $config['model'];

        $user = $model::firstOrNew($attributes)
            ->fill($defaultAttributes);

        if (
            isset($config['attributes']) &&
            !empty($config['attributes'])
        ) {
            $user->fill(static::mapAttributes($config['attributes'], $data, $config));
        }

        $user->save();

        return $user;
    }
}
