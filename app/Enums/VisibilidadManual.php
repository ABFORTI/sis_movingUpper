<?php

namespace App\Enums;

use App\Models\Role;

/** Quién puede abrir un manual además del administrador, que los ve todos. */
enum VisibilidadManual: string {
    case OperadorYAuditor = 'operador_auditor';
    case Operador = 'operador';
    case Auditor = 'auditor';

    /** @return list<string> slugs de rol con acceso */
    public function roles(): array {
        return match ($this) {
            self::OperadorYAuditor => [Role::OPERADOR, Role::AUDITOR],
            self::Operador => [Role::OPERADOR],
            self::Auditor => [Role::AUDITOR],
        };
    }

    public function etiqueta(): string {
        return match ($this) {
            self::OperadorYAuditor => 'Operador y Auditor',
            self::Operador => 'Solo Operador',
            self::Auditor => 'Solo Auditor',
        };
    }

    public function descripcion(): string {
        return match ($this) {
            self::OperadorYAuditor => 'Lo ven operadores y auditores.',
            self::Operador => 'Solo lo ven los operadores.',
            self::Auditor => 'Solo lo ven los auditores.',
        };
    }

    /** @return list<self> visibilidades que incluyen el rol dado */
    public static function paraRol(string $slug): array {
        return array_values(array_filter(self::cases(), fn (self $v) => in_array($slug, $v->roles(), true)));
    }
}
