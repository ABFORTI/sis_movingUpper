<?php

namespace App\Enums;

enum Demo: string {
    case NoAplica = 'Na';
    case A = 'DemoA';
    case B = 'DemoB';
    case C = 'DemoC';
    case D = 'DemoD';

    public function etiqueta(): string {
        return match ($this) {
            self::NoAplica => 'No aplica',
            self::A => 'DEMO A',
            self::B => 'DEMO B',
            self::C => 'DEMO C',
            self::D => 'DEMO D',
        };
    }

    public function esDemo(): bool {
        return $this !== self::NoAplica;
    }
}
