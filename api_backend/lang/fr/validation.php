<?php

declare(strict_types=1);

/*
 * Messages de validation en français (sous-ensemble utilisé par l'API).
 * Les clés non présentes ici retombent sur les messages Laravel par défaut.
 */

return [

    'required' => 'Le champ :attribute est obligatoire.',
    'string' => 'Le champ :attribute doit être une chaîne de caractères.',
    'email' => "Le champ :attribute doit être une adresse e-mail valide.",
    'max' => [
        'string' => 'Le champ :attribute ne peut pas dépasser :max caractères.',
    ],
    'min' => [
        'string' => 'Le champ :attribute doit contenir au moins :min caractères.',
    ],
    'in' => 'La valeur sélectionnée pour :attribute est invalide.',

    'attributes' => [
        'email' => 'adresse e-mail',
        'password' => 'mot de passe',
        'device_name' => "nom de l'appareil",
    ],

    'custom' => [
        'email' => [
            'required' => "L'adresse e-mail est obligatoire.",
            'email' => "L'adresse e-mail est invalide.",
            'max' => "L'adresse e-mail ne peut pas dépasser :max caractères.",
        ],
        'password' => [
            'required' => 'Le mot de passe est obligatoire.',
            'max' => 'Le mot de passe ne peut pas dépasser :max caractères.',
        ],
    ],
];
