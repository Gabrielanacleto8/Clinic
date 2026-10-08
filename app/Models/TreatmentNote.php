<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Evolução ou adendo de um atendimento. Só é inserido, nunca alterado: é a
 * forma de acrescentar informação a um atendimento finalizado.
 */
class TreatmentNote extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @var list<string>
     */
    protected $hidden = ['body'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'body' => 'encrypted',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Evoluções são imutáveis.'));
        static::deleting(fn () => throw new LogicException('Evoluções são imutáveis.'));
    }

    /**
     * @return BelongsTo<Treatment, $this>
     */
    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
