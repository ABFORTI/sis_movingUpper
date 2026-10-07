<?php

return [
    'roles' => [
        [
            'slug' => 'admin',
            'name' => 'Administrador',
            'description' => 'Registra usuarios y puede eliminar información de forma definitiva.',
        ],
        [
            'slug' => 'auditor',
            'name' => 'Auditor',
            'description' => 'Trabaja igual que un operador, pero ve las tarimas y el historial de todos los usuarios.',
        ],
        [
            'slug' => 'operador',
            'name' => 'Operador',
            'description' => 'Escanea, arma tarimas, imprime etiquetas y exporta. Solo borrado lógico.',
        ],
    ],
];
