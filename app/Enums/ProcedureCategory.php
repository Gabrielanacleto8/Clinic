<?php

namespace App\Enums;

enum ProcedureCategory: string
{
    case Injectable = 'injectable';
    case Facial = 'facial';
    case Body = 'body';
    case Hair = 'hair';
    case Laser = 'laser';
    case Consultation = 'consultation';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Injectable => 'Injetáveis',
            self::Facial => 'Facial',
            self::Body => 'Corporal',
            self::Hair => 'Capilar',
            self::Laser => 'Laser e luz',
            self::Consultation => 'Avaliação e retorno',
            self::Other => 'Outros',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_column(
            array_map(fn (self $category) => ['value' => $category->value, 'label' => $category->label()], self::cases()),
            'label',
            'value',
        );
    }
}
