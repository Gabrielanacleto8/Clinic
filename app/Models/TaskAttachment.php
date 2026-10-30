<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Arquivo anexado a uma tarefa. O conteúdo fica criptografado no disco privado
 * pelo AttachmentVault; aqui, só os metadados.
 */
class TaskAttachment extends Model
{
    protected $fillable = ['name'];

    /**
     * @var list<string>
     */
    protected $hidden = ['path'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function sizeLabel(): string
    {
        return $this->size >= 1_048_576
            ? number_format($this->size / 1_048_576, 1, ',', '.').' MB'
            : number_format(max(1, $this->size / 1024), 0, ',', '.').' KB';
    }
}
