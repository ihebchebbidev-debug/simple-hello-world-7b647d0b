<?php

declare(strict_types=1);

return [

    'required' => 'The :attribute field is required.',
    'string' => 'The :attribute field must be a string.',
    'email' => 'The :attribute field must be a valid email address.',
    'max' => [
        'string' => 'The :attribute field must not be greater than :max characters.',
    ],
    'min' => [
        'string' => 'The :attribute field must be at least :min characters.',
    ],
    'in' => 'The selected :attribute is invalid.',

    'attributes' => [
        'email' => 'email address',
        'password' => 'password',
        'device_name' => 'device name',
    ],

    'custom' => [
        'email' => [
            'required' => 'The email address is required.',
            'email' => 'The email address is invalid.',
            'max' => 'The email address must not be greater than :max characters.',
        ],
        'password' => [
            'required' => 'The password is required.',
            'max' => 'The password must not be greater than :max characters.',
        ],
    ],
];
