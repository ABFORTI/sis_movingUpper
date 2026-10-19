<?php

/*
 * Importación del Excel de inventario por almacén.
 *
 * Las columnas se reconocen por el TEXTO del encabezado, nunca por su posición.
 * El encabezado se compara sin acentos, sin mayúsculas, sin puntuación y con
 * los espacios compactados ("Número de Serie del Fabricante" =
 * "numero de serie del fabricante"); la comparación es exacta, así que
 * "Número de serie" no se confunde con "Número de serie de lote".
 *
 * Los nombres base son los de los requerimientos (SKU, modelo, descripción y
 * los tres números de serie). Si el archivo real del cliente usa otros
 * encabezados, agréguelos aquí como alias; no hace falta tocar el código.
 */
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
        // Opcional: va en la columna "Categoría" del manifiesto.
        'posicion' => ['posicion de ubicacion', 'posicion', 'ubicacion', 'posicion ubicacion', 'ubicacion fisica', 'localizacion', 'location'],
        // Opcional: si el archivo la trae, todos sus valores deben ser el almacén elegido.
        'almacen' => ['almacen', 'codigo de almacen', 'clave de almacen', 'alm'],
    ],

    // El encabezado se busca en las primeras filas de la primera hoja.
    'filas_encabezado' => 15,

    'extensiones' => ['xlsx', 'xls', 'csv'],
    'tamano_maximo_kb' => 20480,
    'filas_maximas' => 100000,

    // Opciones que devuelve el selector de SKU por búsqueda.
    'opciones_selector' => 20,
];
