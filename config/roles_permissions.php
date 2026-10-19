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
            'description' => 'Escaneo, Historial (con correcciones), Incidencias, Tráileres, Inventarios y Reporte Diario de todos los usuarios; consulta los manuales publicados y crea y activa mudanzas. No administra usuarios, impresoras ni manuales.',
        ],
        [
            'slug' => 'operador',
            'name' => 'Operador',
            'description' => 'Escanea, arma tarimas, imprime etiquetas y exporta. Solo borrado lógico.',
        ],
    ],
];
