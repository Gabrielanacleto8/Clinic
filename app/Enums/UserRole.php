<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Professional = 'professional';
    case Receptionist = 'receptionist';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::Professional => 'Profissional',
            self::Receptionist => 'Recepção',
        };
    }

    /**
     * Perfis cujo acesso o administrador configura. O administrador tem sempre acesso total.
     *
     * @return list<self>
     */
    public static function configurable(): array
    {
        return [self::Professional, self::Receptionist];
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_column(
            array_map(fn (self $role) => ['value' => $role->value, 'label' => $role->label()], self::cases()),
            'label',
            'value',
        );
    }
}
