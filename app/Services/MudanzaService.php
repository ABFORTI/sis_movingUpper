<?php

namespace App\Services;

use App\Models\Almacen;
use App\Models\Embarque;
use App\Models\Incidencia;
use App\Models\Mudanza;
use App\Models\MudanzaMovimiento;
use App\Models\Role;
use App\Models\Tarima;
use App\Models\User;
use App\Support\Contador;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ciclo de vida de la mudanza. Cada transición valida el estado de origen
 * dentro de una transacción con la fila bloqueada, y queda en la bitácora.
 *
 *   borrador ─activar→ activa ─suspender→ suspendida ─reanudar→ activa
 *   activa ─iniciar_cierre→ en_cierre ─cerrar→ cerrada
 *   en_cierre ─reabrir→ activa;  cerrada ─reabrir→ en_cierre
 *   borrador | activa | suspendida ─cancelar→ cancelada
 */
class MudanzaService {
    public const ACTIVAR = 'activar';
    public const SUSPENDER = 'suspender';
    public const REANUDAR = 'reanudar';
    public const INICIAR_CIERRE = 'iniciar_cierre';
    public const CERRAR = 'cerrar';
    public const REABRIR = 'reabrir';
    public const CANCELAR = 'cancelar';

    /** acción => [estados de origen, estado destino, requiere motivo] */
    public const TRANSICIONES = [
        self::ACTIVAR => [[Mudanza::BORRADOR], Mudanza::ACTIVA, false],
        self::SUSPENDER => [[Mudanza::ACTIVA], Mudanza::SUSPENDIDA, true],
        self::REANUDAR => [[Mudanza::SUSPENDIDA], Mudanza::ACTIVA, false],
        self::INICIAR_CIERRE => [[Mudanza::ACTIVA], Mudanza::EN_CIERRE, false],
        self::CERRAR => [[Mudanza::EN_CIERRE], Mudanza::CERRADA, false],
        self::REABRIR => [[Mudanza::EN_CIERRE, Mudanza::CERRADA], null, true],
        self::CANCELAR => [[Mudanza::BORRADOR, Mudanza::ACTIVA, Mudanza::SUSPENDIDA], Mudanza::CANCELADA, true],
    ];

    public const ETIQUETAS = [
        self::ACTIVAR => 'Activar',
        self::SUSPENDER => 'Suspender',
        self::REANUDAR => 'Reanudar',
        self::INICIAR_CIERRE => 'Iniciar cierre',
        self::CERRAR => 'Cerrar',
        self::REABRIR => 'Reabrir',
        self::CANCELAR => 'Cancelar',
    ];

    /** Lo único que el auditor hace en el ciclo de vida (además de crear): activar. El resto es del administrador. */
    public const ACCIONES_AUDITOR = [self::ACTIVAR];

    public function __construct(private readonly BitacoraService $bitacora) { }

    /**
     * @param array{cliente: string, notas?: ?string} $datos
     * @param list<array{origen: string, destino: string}> $movimientos
     */
    public function crear(array $datos, array $movimientos, User $usuario): Mudanza {
        return DB::transaction(function () use ($datos, $movimientos, $usuario) {
            $mudanza = Mudanza::create($datos + [
                'folio' => Mudanza::folioPara(Contador::siguiente(Mudanza::CONTADOR_FOLIO)),
                'estado' => Mudanza::BORRADOR,
                'created_by' => $usuario->id,
            ]);
            $this->sincronizarMovimientos($mudanza, $movimientos);

            $this->bitacora->registrar('mudanza.creada', $usuario, $mudanza, $datos + ['movimientos' => $mudanza->rutas], mudanza: $mudanza);

            return $mudanza;
        });
    }

    /** @param list<array{origen: string, destino: string}> $movimientos */
    public function actualizar(Mudanza $mudanza, array $datos, array $movimientos, User $usuario): void {
        if (in_array($mudanza->estado, [Mudanza::CERRADA, Mudanza::CANCELADA], true)) {
            throw ValidationException::withMessages(['cliente' => "La {$mudanza->folio} está {$mudanza->estado_nombre} y no se edita."]);
        }

        DB::transaction(function () use ($mudanza, $datos, $movimientos, $usuario) {
            $antes = $mudanza->only(array_keys($datos)) + ['movimientos' => $mudanza->rutas];
            $mudanza->update($datos);
            $this->sincronizarMovimientos($mudanza, $movimientos);
            $mudanza->unsetRelation('movimientos');

            $this->bitacora->registrar('mudanza.editada', $usuario, $mudanza, [
                'antes' => $antes,
                'despues' => $datos + ['movimientos' => $mudanza->rutas],
            ], mudanza: $mudanza);
        });
    }

    /**
     * Deja la mudanza con exactamente esos movimientos. Los almacenes se crean
     * por código si no existen. Un movimiento que ya tiene tarimas o embarques
     * no se puede quitar (perdería su trazabilidad).
     *
     * @param list<array{origen: string, destino: string}> $movimientos
     */
    private function sincronizarMovimientos(Mudanza $mudanza, array $movimientos): void {
        $clave = fn ($m) => $m['almacen_origen_id'].'-'.$m['almacen_destino_id'];
        $deseados = collect($movimientos)->map(fn (array $m) => [
            'almacen_origen_id' => $this->almacen($m['origen'])->id,
            'almacen_destino_id' => $this->almacen($m['destino'])->id,
        ])->unique($clave);
        $deseadosClaves = $deseados->map($clave)->all();

        $actuales = MudanzaMovimiento::with(['origen', 'destino'])->where('mudanza_id', $mudanza->id)->lockForUpdate()->get();
        foreach ($actuales as $actual) {
            if (in_array($clave($actual), $deseadosClaves, true)) {
                continue;
            }
            if (Tarima::where('mudanza_movimiento_id', $actual->id)->exists() || Embarque::where('mudanza_movimiento_id', $actual->id)->exists()) {
                throw ValidationException::withMessages([
                    'movimientos' => "El movimiento {$actual->nombre} ya tiene embarques o tráileres: no se puede quitar.",
                ]);
            }
            $actual->delete();
        }

        $existentes = $actuales->map($clave)->all();
        foreach ($deseados as $m) {
            if (! in_array($clave($m), $existentes, true)) {
                MudanzaMovimiento::create($m + ['mudanza_id' => $mudanza->id]);
            }
        }
    }

    private function almacen(string $codigo): Almacen {
        return Almacen::firstOrCreate(['codigo' => Almacen::normalizarCodigo($codigo)]);
    }

    /** @return list<string> acciones posibles desde el estado actual (las que ese usuario puede hacer, si se indica) */
    public function accionesPara(Mudanza $mudanza, ?User $usuario = null): array {
        return array_values(array_filter(
            array_keys(array_filter(self::TRANSICIONES, fn (array $t) => in_array($mudanza->estado, $t[0], true))),
            fn (string $accion) => $usuario === null || $this->puede($usuario, $accion),
        ));
    }

    public function puede(User $usuario, string $accion): bool {
        return $usuario->isAdmin() || ($usuario->hasRole(Role::AUDITOR) && in_array($accion, self::ACCIONES_AUDITOR, true));
    }

    public function transicionar(Mudanza $mudanza, string $accion, User $usuario, ?string $motivo = null): void {
        if (! $this->puede($usuario, $accion)) {
            throw new AuthorizationException('Solo el administrador puede '.mb_strtolower(self::ETIQUETAS[$accion] ?? $accion).' una mudanza.');
        }

        [$origenes, $destino, $requiereMotivo] = self::TRANSICIONES[$accion]
            ?? throw ValidationException::withMessages(['accion' => 'Acción no válida.']);

        $motivo = trim((string) $motivo) ?: null;
        if ($requiereMotivo && mb_strlen((string) $motivo) < 5) {
            throw ValidationException::withMessages(['motivo' => 'Escribe el motivo (al menos 5 caracteres).']);
        }

        try {
            DB::transaction(function () use ($mudanza, $accion, $origenes, $destino, $usuario, $motivo) {
                $actual = Mudanza::whereKey($mudanza->id)->lockForUpdate()->firstOrFail();

                if (! in_array($actual->estado, $origenes, true)) {
                    throw ValidationException::withMessages([
                        'accion' => "La {$actual->folio} está {$actual->estado_nombre}; no se puede ".mb_strtolower(self::ETIQUETAS[$accion]).'.',
                    ]);
                }

                $destino ??= $actual->estado === Mudanza::CERRADA ? Mudanza::EN_CIERRE : Mudanza::ACTIVA;
                $this->validar($actual, $accion);

                $cambios = ['estado' => $destino];
                if ($accion === self::ACTIVAR) {
                    $cambios += ['activada_at' => now(), 'activada_by' => $usuario->id];
                }
                if ($destino === Mudanza::CERRADA || $destino === Mudanza::CANCELADA) {
                    $cambios += ['cerrada_at' => now(), 'cerrada_by' => $usuario->id];
                }
                if ($accion === self::REABRIR) {
                    $cambios += ['cerrada_at' => null, 'cerrada_by' => null];
                }

                Mudanza::whereKey($actual->id)->update($cambios);

                $this->bitacora->registrar("mudanza.{$accion}", $usuario, $actual, [
                    'de' => $actual->estado,
                    'a' => $destino,
                ], $motivo, $actual);
            });
        } catch (UniqueConstraintViolationException) {
            $enCurso = Mudanza::enCurso()->value('folio');
            throw ValidationException::withMessages([
                'accion' => "Ya hay una mudanza en curso ({$enCurso}). Ciérrala o cancélala antes.",
            ]);
        }

        $mudanza->refresh();
    }

    /** Reglas de negocio de cada transición, además del estado de origen. */
    private function validar(Mudanza $mudanza, string $accion): void {
        if ($accion === self::ACTIVAR) {
            $faltan = array_filter([
                blank($mudanza->cliente) ? 'el cliente' : null,
                $mudanza->movimientos()->doesntExist() ? 'al menos un movimiento entre almacenes' : null,
            ]);
            if ($faltan) {
                throw ValidationException::withMessages(['accion' => 'Para activar captura '.implode(' y ', $faltan).'.']);
            }
        }

        if ($accion === self::CERRAR) {
            $abiertas = Tarima::temporales()->where('mudanza_id', $mudanza->id)->orderBy('numero')->get();
            if ($abiertas->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'accion' => 'Guarda o anula los embarques en captura antes de cerrar: '.$abiertas->pluck('codigo')->join(', ').'.',
                ]);
            }

            $embarques = Embarque::abiertos()->where('mudanza_id', $mudanza->id)->orderBy('folio')->pluck('folio');
            if ($embarques->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'accion' => 'Hay tráileres sin recibir: '.$embarques->join(', ').'. Cierra su recepción antes de cerrar la mudanza.',
                ]);
            }

            $incidencias = Incidencia::vigentes()->where('mudanza_id', $mudanza->id)->orderBy('folio')->pluck('folio');
            if ($incidencias->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'accion' => 'Resuelve las incidencias abiertas antes de cerrar: '.$incidencias->join(', ').'.',
                ]);
            }
        }

        if ($accion === self::CANCELAR) {
            $guardadas = Tarima::guardadas()->vigentes()->where('mudanza_id', $mudanza->id)->count();
            if ($guardadas > 0) {
                throw ValidationException::withMessages([
                    'accion' => "La {$mudanza->folio} tiene {$guardadas} embarques guardados: ciérrala en lugar de cancelarla, o anula esos embarques primero.",
                ]);
            }
        }
    }
}
