<?php

namespace GrantHolle\PowerSchool\Auth\Transformers;

class Lowercase
{
    public function __invoke($value)
    {
        return strtolower($value);
    }
}