<?php

namespace App\KpiApi\Exceptions;

use App\KpiApi\KpiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ExceptionRenderer {
    public static function render(Throwable $e, Request $request): ?JsonResponse {
        if ($e instanceof HttpResponseException) {
            return null;
        }

        if ($e instanceof KpiApiException) {
            return KpiResponse::error($request, $e->status, $e->errores);
        }

        if ($e instanceof AuthenticationException) {
            return KpiResponse::error($request, 401, [KpiApiException::error(
                KpiApiException::NO_AUTENTICADO,
                'Token ausente, inválido o revocado. Envíe el encabezado Authorization: Bearer <token>.'
            )], ['WWW-Authenticate' => 'Bearer']);
        }

        if ($e instanceof ValidationException) {
            $errores = [];
            foreach ($e->errors() as $campo => $mensajes) {
                foreach ($mensajes as $mensaje) {
                    $errores[] = KpiApiException::error(KpiApiException::PARAMETRO_INVALIDO, $mensaje, $campo);
                }
            }

            return KpiResponse::error($request, 422, $errores);
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            [$codigo, $mensaje] = match (true) {
                $status === 403 => [KpiApiException::SIN_PERMISO, 'El token no tiene la ability requerida (kpis:read).'],
                $status === 404 => [KpiApiException::NO_ENCONTRADO, 'La ruta solicitada no existe en esta versión de la API.'],
                $status === 405 => [KpiApiException::METODO_NO_PERMITIDO, 'La API es de solo lectura: únicamente se permite GET.'],
                $status === 429 => [KpiApiException::LIMITE_PETICIONES, 'Se excedió el límite de peticiones. Reintente después de Retry-After segundos.'],
                $status === 503 => [KpiApiException::SERVICIO_NO_DISPONIBLE, 'El sistema origen no está disponible temporalmente.'],
                $status >= 500 => [KpiApiException::ERROR_INTERNO, 'Error interno del sistema origen.'],
                default => [KpiApiException::SOLICITUD_INVALIDA, 'La solicitud no es válida.'],
            };

            return KpiResponse::error($request, $status, [KpiApiException::error($codigo, $mensaje)], $e->getHeaders());
        }

        return KpiResponse::error($request, 500, [KpiApiException::error(
            KpiApiException::ERROR_INTERNO,
            'Error interno del sistema origen. Reporte el id_peticion al equipo responsable.'
        )]);
    }
}
