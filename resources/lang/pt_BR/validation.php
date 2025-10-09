<?php

return [
    'required' => 'O campo :attribute é obrigatório.',
    'unique' => 'O campo :attribute já está em uso.',
    'email' => 'O campo :attribute deve ser um endereço de e-mail válido.',
    'max' => [
        'string' => 'O campo :attribute não pode ter mais que :max caracteres.',
    ],
    'confirmed' => 'A confirmação do campo :attribute não confere.',

    'attributes' => [
        'username' => 'usuário',
        'name' => 'nome',
        'email' => 'e-mail',
        'telefone' => 'telefone',
        'password' => 'senha',
    ],
    'custom' => [
        'username' => [
            'regex' => 'O nome de usuário não pode conter espaços.',
        ],
        'password' => [
            'regex' => 'A senha deve conter pelo menos uma letra, um número e um caractere especial (@, $, !, %, *, ?, &).',
        ],
    ],
];
