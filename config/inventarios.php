<?php

return [
    'columnas' => [
        // Obligatoria.
        'sku' => ['sku', 'numero de parte', 'no de parte', 'num de parte', 'numero de parte sku', 'codigo', 'codigo sku', 'material', 'articulo'],
        'modelo' => ['modelo', 'model'],
        'descripcion' => ['descripcion', 'descripcion del producto', 'descripcion del material', 'texto breve de material', 'description'],
        // Se exige al menos una de las tres.
        'ns_fabricante' => ['numero de serie del fabricante', 'no de serie del fabricante', 'num de serie del fabricante', 'n/s del fabricante', 'n/s fabricante', 'ns fabricante', 'serie del fabricante', 'serie fabricante'],
        'numero_serie' => ['numero de serie', 'no de serie', 'num de serie', 'n/s', 'ns', 'serie'],
        'ns_lote' => ['numero de serie de lote', 'no de serie de lote', 'num de serie de lote', 'n/s de lote', 'n/s lote', 'ns lote', 'serie de lote', 'numero de lote', 'lote'],
        'posicion' => ['posicion de ubicacion', 'posicion', 'ubicacion', 'posicion ubicacion', 'ubicacion fisica', 'localizacion', 'location'],
        'almacen' => ['almacen', 'codigo de almacen', 'clave de almacen', 'alm'],
    ],

    'filas_encabezado' => 15,
    'extensiones' => ['xlsx', 'xls', 'csv'],
    'tamano_maximo_kb' => 20480,
    'filas_maximas' => 100000,
    'opciones_selector' => 20,
];
