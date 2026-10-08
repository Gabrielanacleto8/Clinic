<?php

namespace App\Enums;

enum ProductUnit: string
{
    case Unit = 'un';
    case Milliliter = 'ml';
    case ToxinUnit = 'u';
    case Gram = 'g';
    case Milligram = 'mg';
    case Vial = 'fr';
    case Ampoule = 'amp';
    case Syringe = 'ser';
    case Box = 'cx';

    /**
     * Abreviação exibida ao lado das quantidades.
     */
    public function symbol(): string
    {
        return match ($this) {
            self::Milliliter => 'mL',
            self::ToxinUnit => 'U',
            default => $this->value,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Unit => 'Unidade (un)',
            self::Milliliter => 'Mililitro (mL)',
            self::ToxinUnit => 'Unidade de toxina (U)',
            self::Gram => 'Grama (g)',
            self::Milligram => 'Miligrama (mg)',
            self::Vial => 'Frasco (fr)',
            self::Ampoule => 'Ampola (amp)',
            self::Syringe => 'Seringa (ser)',
            self::Box => 'Caixa (cx)',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_column(
            array_map(fn (self $unit) => ['value' => $unit->value, 'label' => $unit->label()], self::cases()),
            'label',
            'value',
        );
    }
}
