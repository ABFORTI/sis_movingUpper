<?php

use App\Http\Controllers\Admin\ImpresoraController;
use App\Http\Controllers\Admin\MudanzaController;
use App\Http\Controllers\Admin\UsuarioController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CorreccionTarimaController;
use App\Http\Controllers\DiagnosticoImpresionController;
use App\Http\Controllers\EmbarqueController;
use App\Http\Controllers\EntregaController;
use App\Http\Controllers\EscaneoController;
use App\Http\Controllers\EtiquetaController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\HistorialController;
use App\Http\Controllers\IncidenciaController;
use App\Http\Controllers\InventarioController;
use App\Http\Controllers\ManualController;
use App\Http\Controllers\PruebaImpresionController;
use App\Http\Controllers\TarimaController;
use App\Http\Controllers\WmsController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:10,1');
});

Route::get('/t/{tarima}', [TarimaController::class, 'publico'])
    ->middleware(['signed', 'throttle:60,1'])
    ->name('tarimas.publico');

Route::middleware(['auth', 'activo'])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', [WmsController::class, 'index'])->name('wms');

    Route::post('/escaneo/padre', [EscaneoController::class, 'activarPadre'])->name('escaneo.padre');
    Route::post('/escaneo/padre/cerrar', [EscaneoController::class, 'cerrarPadre'])->name('escaneo.padre.cerrar');
    Route::post('/escaneo/ns', [EscaneoController::class, 'agregarNumeroSerie'])->name('escaneo.ns');
    Route::get('/escaneo/skus', [EscaneoController::class, 'skus'])->middleware('throttle:240,1')->name('escaneo.skus');

    // Inventarios por almacén: todos consultan y exportan; cargar es de supervisión (abajo).
    Route::get('/inventarios', [InventarioController::class, 'index'])->name('inventarios.index');
    Route::get('/inventarios/{almacen}/excel', [InventarioController::class, 'exportar'])->name('inventarios.exportar');
    // Alta de un SKU que aún no viene en el inventario: cualquier usuario, desde el handheld.
    Route::post('/inventarios/skus', [InventarioController::class, 'altaSku'])->middleware('throttle:60,1')->name('inventarios.skus.store');

    Route::post('/tarimas', [TarimaController::class, 'store'])->name('tarimas.store');
    Route::delete('/tarimas/{tarima}/contenido', [TarimaController::class, 'vaciar'])->can('vaciar', 'tarima')->name('tarimas.vaciar');
    Route::post('/tarimas/{tarima}/anomalias', [EscaneoController::class, 'anomalias'])->can('editar', 'tarima')->name('tarimas.anomalias');
    Route::post('/tarimas/{tarima}/modelos/{registro}/ns', [EscaneoController::class, 'agregarAlModelo'])->can('editar', 'tarima')->name('tarimas.ns.agregar');
    Route::put('/tarimas/{tarima}/ns/{numeroSerie}', [EscaneoController::class, 'editarNumeroSerie'])->can('editar', 'tarima')->name('tarimas.ns.editar');
    Route::post('/tarimas/{tarima}/impresion', [TarimaController::class, 'marcarImpresa'])->can('guardar', 'tarima')->name('tarimas.impresa');
    Route::post('/tarimas/{tarima}/guardar', [TarimaController::class, 'guardar'])->can('guardar', 'tarima')->name('tarimas.guardar');
    Route::post('/tarimas/{tarima}/anular', [TarimaController::class, 'anular'])->can('anular', 'tarima')->name('tarimas.anular');
    Route::get('/tarimas/{tarima}/corregir', [CorreccionTarimaController::class, 'show'])->can('corregir', 'tarima')->name('tarimas.corregir');
    Route::post('/tarimas/{tarima}/partidas/mover', [CorreccionTarimaController::class, 'mover'])->can('corregir', 'tarima')->name('tarimas.partidas.mover');
    Route::post('/tarimas/{tarima}/partidas/retirar', [CorreccionTarimaController::class, 'retirar'])->can('corregir', 'tarima')->name('tarimas.partidas.retirar');
    Route::post('/tarimas/{tarima}/partidas/eliminar', [CorreccionTarimaController::class, 'eliminar'])->can('corregir', 'tarima')->name('tarimas.partidas.eliminar');
    Route::post('/tarimas/{tarima}/partidas/completar', [CorreccionTarimaController::class, 'completar'])->can('corregir', 'tarima')->name('tarimas.partidas.completar');
    Route::post('/tarimas/{tarima}/partidas/agregar', [CorreccionTarimaController::class, 'agregar'])->can('corregir', 'tarima')->name('tarimas.partidas.agregar');
    Route::put('/tarimas/{tarima}/partidas/editar', [CorreccionTarimaController::class, 'editar'])->can('corregir', 'tarima')->name('tarimas.partidas.editar');

    Route::get('/tarimas/{tarima}/etiqueta', [EtiquetaController::class, 'show'])->can('view', 'tarima')->name('etiquetas.show');
    Route::get('/tarimas/{tarima}/etiqueta.zpl', [EtiquetaController::class, 'zpl'])->can('view', 'tarima')->name('etiquetas.zpl');

    Route::get('/excel', [ExportController::class, 'todas'])->name('export.todas');

    Route::get('/historial', [HistorialController::class, 'index'])->name('historial');

    Route::get('/manuales', [ManualController::class, 'index'])->name('manuales.index');
    Route::get('/manuales/{manual}/ver', [ManualController::class, 'ver'])->can('view', 'manual')->name('manuales.ver');
    Route::get('/manuales/{manual}/descargar', [ManualController::class, 'descargar'])->can('view', 'manual')->name('manuales.descargar');

    Route::get('/impresion/prueba', [PruebaImpresionController::class, 'show'])->name('impresion.prueba');
    Route::get('/impresion/prueba.zpl', [PruebaImpresionController::class, 'zpl'])->name('impresion.prueba.zpl');
    Route::get('/impresion/configuracion.zpl', [PruebaImpresionController::class, 'configuracion'])->name('impresion.configuracion.zpl');
    Route::post('/impresion/diagnostico', DiagnosticoImpresionController::class)
        ->middleware('throttle:30,1')
        ->name('impresion.diagnostico');

    // Incidencias: todos registran, comentan y adjuntan evidencia.
    Route::get('/incidencias', [IncidenciaController::class, 'index'])->name('incidencias.index');
    Route::get('/incidencias/nueva', [IncidenciaController::class, 'create'])->name('incidencias.create');
    Route::post('/incidencias', [IncidenciaController::class, 'store'])->name('incidencias.store');
    Route::get('/incidencias/{incidencia}', [IncidenciaController::class, 'show'])->name('incidencias.show');
    Route::post('/incidencias/{incidencia}/comentarios', [IncidenciaController::class, 'comentar'])->name('incidencias.comentar');
    Route::get('/evidencias/{evidencia}', [IncidenciaController::class, 'evidencia'])->name('evidencias.ver');

    // Andén y destino: cualquier usuario escanea para cargar o recibir.
    Route::get('/embarques', [EmbarqueController::class, 'index'])->name('embarques.index');
    Route::get('/embarques/{embarque}', [EmbarqueController::class, 'show'])->whereNumber('embarque')->name('embarques.show');
    Route::post('/embarques/{embarque}/cargar', [EmbarqueController::class, 'cargar'])->name('embarques.cargar');
    Route::post('/embarques/{embarque}/tarimas/{tarima}/bajar', [EmbarqueController::class, 'bajar'])->name('embarques.bajar');
    Route::post('/embarques/{embarque}/recibir', [EmbarqueController::class, 'recibir'])->name('embarques.recibir');
    Route::get('/embarques/{embarque}/manifiesto', [EmbarqueController::class, 'manifiesto'])->whereNumber('embarque')->name('embarques.manifiesto');

    // Supervisión (admin y auditor): incidencias, entregas y embarques.
    Route::middleware('role:admin,auditor')->group(function () {
        Route::post('/incidencias/{incidencia}/piezas', [IncidenciaController::class, 'vincular'])->name('incidencias.vincular');
        Route::post('/incidencias/{incidencia}/responsable', [IncidenciaController::class, 'asignar'])->name('incidencias.asignar');
        Route::post('/incidencias/{incidencia}/resolver', [IncidenciaController::class, 'resolver'])->name('incidencias.resolver');
        Route::post('/incidencias/{incidencia}/reabrir', [IncidenciaController::class, 'reabrir'])->name('incidencias.reabrir');

        Route::post('/inventarios', [InventarioController::class, 'store'])->middleware('throttle:20,1')->name('inventarios.store');

        // Mudanzas: el auditor las consulta, crea y activa; editar y el resto de
        // transiciones son del administrador (MudanzaRequest / MudanzaService::puede).
        Route::get('/mudanzas', [MudanzaController::class, 'index'])->name('mudanzas.index');
        Route::get('/mudanzas/create', [MudanzaController::class, 'create'])->name('mudanzas.create');
        Route::post('/mudanzas', [MudanzaController::class, 'store'])->name('mudanzas.store');
        Route::post('/mudanzas/{mudanza}/estado', [MudanzaController::class, 'estado'])->name('mudanzas.estado');

        Route::get('/entregas', [EntregaController::class, 'index'])->name('entregas.index');
        Route::post('/entregas', [EntregaController::class, 'store'])->name('entregas.store');
        Route::get('/entregas/{entrega}/archivo', [EntregaController::class, 'descargar'])->name('entregas.descargar');


        Route::post('/embarques', [EmbarqueController::class, 'store'])->name('embarques.store');
        Route::get('/embarques/reporte-final', [EmbarqueController::class, 'reporte'])->name('embarques.reporte');
        Route::post('/embarques/{embarque}/salida', [EmbarqueController::class, 'despachar'])->name('embarques.despachar');
        Route::post('/embarques/{embarque}/cerrar', [EmbarqueController::class, 'cerrar'])->name('embarques.cerrar');
    });

    Route::middleware('role:admin')->group(function () {
        Route::get('/mudanzas/{mudanza}/edit', [MudanzaController::class, 'edit'])->name('mudanzas.edit');
        Route::put('/mudanzas/{mudanza}', [MudanzaController::class, 'update'])->name('mudanzas.update');
        Route::resource('usuarios', UsuarioController::class)
            ->parameters(['usuarios' => 'usuario'])
            ->except(['show', 'destroy']);
        Route::resource('impresoras', ImpresoraController::class)
            ->parameters(['impresoras' => 'impresora'])
            ->except(['show', 'destroy']);
        Route::resource('manuales', ManualController::class)
            ->parameters(['manuales' => 'manual'])
            ->except(['index', 'show']);
    });
});
