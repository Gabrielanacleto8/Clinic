<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;

class CashClosingLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['payment_method', 'expected', 'counted'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_method' => PaymentMethod::class,
            'expected' => 'decimal:2',
            'counted' => 'decimal:2',
        ];
    }

    /**
     * Conferido menos esperado: sobra (positiva) ou falta (negativa).
     */
    public function difference(): float
    {
        return round((float) $this->counted - (float) $this->expected, 2);
    }
}
