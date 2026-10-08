<?php

namespace App\Enums;

enum PhotoStage: string
{
    case Before = 'before';
    case After = 'after';
    case FollowUp = 'follow_up';

    public function label(): string
    {
        return match ($this) {
            self::Before => 'Antes',
            self::After => 'Depois',
            self::FollowUp => 'Acompanhamento',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_column(
            array_map(fn (self $stage) => ['value' => $stage->value, 'label' => $stage->label()], self::cases()),
            'label',
            'value',
        );
    }
}
