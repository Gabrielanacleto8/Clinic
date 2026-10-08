<?php

namespace App\Enums;

enum ProductCategory: string
{
    case Injectable = 'injectable';
    case Anesthetic = 'anesthetic';
    case Cosmetic = 'cosmetic';
    case Disposable = 'disposable';
    case Equipment = 'equipment';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Injectable => 'Injetáveis',
            self::Anesthetic => 'Anestésicos',
            self::Cosmetic => 'Dermocosméticos',
            self::Disposable => 'Descartáveis',
            self::Equipment => 'Equipamentos e acessórios',
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
