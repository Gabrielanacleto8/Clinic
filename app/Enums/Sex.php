<?php

namespace App\Enums;

enum Sex: string
{
    case Female = 'F';
    case Male = 'M';
    case Other = 'O';

    public function label(): string
    {
        return match ($this) {
            self::Female => 'Feminino',
            self::Male => 'Masculino',
            self::Other => 'Outro',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_column(
            array_map(fn (self $sex) => ['value' => $sex->value, 'label' => $sex->label()], self::cases()),
            'label',
            'value',
        );
    }
}
