<?php

use App\Http\Controllers\Admin\UsuarioController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DiagnosticoImpresionController;
use App\Http\Controllers\EscaneoController;
use App\Http\Controllers\EtiquetaController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\HistorialController;
use App\Http\Controllers\ManualController;
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
    Route::post('/modo', [WmsController::class, 'modo'])->name('wms.modo');

    Route::post('/escaneo/padre', [EscaneoController::class, 'activarPadre'])->name('escaneo.padre');
    Route::post('/escaneo/padre/cerrar', [EscaneoController::class, 'cerrarPadre'])->name('escaneo.padre.cerrar');
    Route::post('/escaneo/ns', [EscaneoController::class, 'agregarNumeroSerie'])->name('escaneo.ns');
    Route::post('/escaneo/simple', [EscaneoController::class, 'agregarSimple'])->name('escaneo.simple');

    Route::post('/tarimas', [TarimaController::class, 'store'])->name('tarimas.store');
    Route::delete('/tarimas/{tarima}/contenido', [TarimaController::class, 'vaciar'])->can('vaciar', 'tarima')->name('tarimas.vaciar');
    Route::post('/tarimas/{tarima}/impresion', [TarimaController::class, 'marcarImpresa'])->can('guardar', 'tarima')->name('tarimas.impresa');
    Route::post('/tarimas/{tarima}/guardar', [TarimaController::class, 'guardar'])->can('guardar', 'tarima')->name('tarimas.guardar');

    Route::get('/tarimas/{tarima}/etiqueta', [EtiquetaController::class, 'show'])->can('view', 'tarima')->name('etiquetas.show');
    Route::get('/tarimas/{tarima}/etiqueta.zpl', [EtiquetaController::class, 'zpl'])->can('view', 'tarima')->name('etiquetas.zpl');

    Route::get('/tarimas/{tarima}/excel', [ExportController::class, 'tarima'])->can('view', 'tarima')->name('export.tarima');
    Route::get('/excel', [ExportController::class, 'todas'])->name('export.todas');

    Route::get('/historial', [HistorialController::class, 'index'])->name('historial');

    Route::get('/manuales', [ManualController::class, 'index'])->name('manuales.index');
    Route::get('/manuales/{manual}/ver', [ManualController::class, 'ver'])->can('view', 'manual')->name('manuales.ver');
    Route::get('/manuales/{manual}/descargar', [ManualController::class, 'descargar'])->can('view', 'manual')->name('manuales.descargar');

    Route::post('/impresion/diagnostico', DiagnosticoImpresionController::class)
        ->middleware('throttle:30,1')
        ->name('impresion.diagnostico');

    Route::middleware('role:admin')->group(function () {
        Route::delete('/tarimas/{tarima}', [TarimaController::class, 'destroy'])->name('tarimas.destroy');
        Route::resource('usuarios', UsuarioController::class)
            ->parameters(['usuarios' => 'usuario'])
            ->except(['show', 'destroy']);
        Route::resource('manuales', ManualController::class)
            ->parameters(['manuales' => 'manual'])
            ->except(['index', 'show']);
    });
});
