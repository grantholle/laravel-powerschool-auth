<?php

namespace GrantHolle\PowerSchool\Auth;

use Closure;
use GrantHolle\PowerSchool\Auth\Exceptions\UnableToDetectUserTypeException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

class UserFactory
{
    protected static ?Closure $findUserResolver = null;

    public static function findUserUsing(callable $function): void
    {
        static::$findUserResolver = $function;
    }

    public static function mapAttributes(array $configAttributes, Collection $data, array $config): array
    {
        $transformers = $config['attribute_transformers'] ?? [];

        return collect($configAttributes)
            ->filter(fn ($modelKey, $dataKey) => $data->has($dataKey))
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
        /** @var string $type */
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
        $attributes = static::mapAttributes($config['identifying_attributes'] ?? [], $data, $config);

        $model = $config['model'] ?? '';

        $resolver = static::$findUserResolver ?:
            function (Collection $data, string $model, array $attributes) {
                return $model::firstOrNew($attributes);
            };

        $user = $resolver($data, $model, $attributes);

        if (method_exists($user, 'forceFill')) {
            $configAttributes = static::mapAttributes(($config['attributes'] ?? []), $data, $config);
            $attributes = array_merge($defaultAttributes, $configAttributes);
            $user->forceFill($attributes);
        }

        if (method_exists($user, 'save')) {
            $user->save();
        }

        return $user;
    }
}
