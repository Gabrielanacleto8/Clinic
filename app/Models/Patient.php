<?php

namespace App\Models;

use App\Enums\Sex;
use App\Support\BlindIndex;
use App\Support\Cpf;
use App\Support\Phone;
use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'name', 'cpf', 'birth_date', 'sex', 'phone', 'email',
    'zip_code', 'street', 'number', 'complement', 'district', 'city', 'state', 'notes',
    'guardian_name', 'guardian_cpf', 'guardian_relationship', 'guardian_phone',
])]
class Patient extends Model
{
    /** @use HasFactory<PatientFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Nunca serializar dados sensíveis por acidente (JSON, logs, filas).
     *
     * @var list<string>
     */
    protected $hidden = ['cpf', 'cpf_hash', 'notes', 'avatar_path', 'guardian_cpf'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cpf' => 'encrypted',
            'notes' => 'encrypted',
            'guardian_cpf' => 'encrypted',
            'birth_date' => 'immutable_date',
            'image_use_consented_at' => 'immutable_datetime',
            'sex' => Sex::class,
        ];
    }

    protected static function booted(): void
    {
        // Mantém o índice cego sempre em sincronia com o CPF gravado.
        static::saving(function (Patient $patient): void {
            if ($patient->isDirty('cpf')) {
                $digits = Cpf::digits($patient->cpf);

                $patient->cpf = $digits;
                $patient->cpf_hash = BlindIndex::for($digits);
            }
        });
    }

    /**
     * Iniciais do nome, mostradas no lugar da foto de identificação quando ela não existe.
     */
    public function initials(): string
    {
        $words = preg_split('/\s+/u', trim($this->name), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];
        $first = $words[0];
        $last = count($words) > 1 ? end($words) : '';

        return mb_strtoupper(mb_substr($first, 0, 1).mb_substr($last, 0, 1));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasOne<PatientAnamnesis, $this>
     */
    public function anamnesis(): HasOne
    {
        return $this->hasOne(PatientAnamnesis::class);
    }

    /**
     * Versões anteriores da ficha de saúde; a rota da versão é resolvida por aqui.
     *
     * @return HasManyThrough<PatientAnamnesisVersion, PatientAnamnesis, $this>
     */
    public function anamnesisVersions(): HasManyThrough
    {
        return $this->hasManyThrough(PatientAnamnesisVersion::class, PatientAnamnesis::class);
    }

    /**
     * @return HasMany<TreatmentPlan, $this>
     */
    public function treatmentPlans(): HasMany
    {
        return $this->hasMany(TreatmentPlan::class);
    }

    /**
     * @return HasMany<Treatment, $this>
     */
    public function treatments(): HasMany
    {
        return $this->hasMany(Treatment::class);
    }

    /**
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * Fotos do paciente, avulsas ou de atendimentos que não foram arquivados.
     * É por aqui que a rota /pacientes/{patient}/fotos/{photo} resolve a foto.
     *
     * @return HasMany<PatientAttachment, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(PatientAttachment::class)
            ->photos()
            ->where(fn (Builder $query) => $query->whereNull('treatment_id')->orWhereHas('treatment'));
    }

    /**
     * Busca por nome (parcial), por telefone (parcial, só dígitos) ou por CPF
     * completo (via índice cego). Onze dígitos podem ser um CPF ou um celular
     * com DDD: os dois são tentados.
     *
     * @param  Builder<Patient>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $digits = Cpf::digits($term);
        $numeric = ! preg_match('/[a-z]/iu', $term) && $digits !== '';

        if ($numeric && strlen($digits) >= 8 && strlen($digits) <= 11) {
            $query->where(function (Builder $inner) use ($digits): void {
                $inner->where('phone', 'like', '%'.$digits.'%');

                if (strlen($digits) === 11) {
                    $inner->orWhere('cpf_hash', BlindIndex::for($digits));
                }
            });

            return;
        }

        // Escapa curingas para o termo ser tratado como texto literal.
        $query->where('name', 'like', '%'.addcslashes($term, '\\%_').'%');
    }

    /**
     * Menor de 18 anos: exige responsável legal no cadastro.
     */
    public function isMinor(): bool
    {
        return $this->age() < 18;
    }

    public function formattedGuardianPhone(): string
    {
        return Phone::format($this->guardian_phone);
    }

    /**
     * Telefone só com dígitos, para links de ligação e WhatsApp.
     */
    public function phoneDigits(): string
    {
        return (string) $this->phone;
    }

    public function formattedCpf(): string
    {
        return Cpf::format($this->cpf);
    }

    public function maskedCpf(): string
    {
        return Cpf::mask($this->cpf);
    }

    public function formattedPhone(): string
    {
        return Phone::format($this->phone);
    }

    public function formattedZipCode(): string
    {
        $zip = (string) $this->zip_code;

        return strlen($zip) === 8 ? substr($zip, 0, 5).'-'.substr($zip, 5) : $zip;
    }

    public function age(): int
    {
        return (int) $this->birth_date->diffInYears(now());
    }
}
