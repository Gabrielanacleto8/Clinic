<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Treatment;
use App\Models\User;

/**
 * Um atendimento em andamento (rascunho) é de quem o registrou, do
 * profissional responsável e do administrador. Depois de finalizado ninguém
 * o altera: só se acrescentam evoluções, fotos e documentos.
 */
class TreatmentPolicy
{
    /**
     * Tela de atendimentos: quem espera e quem está sendo atendido. É a
     * agenda do dia em andamento, sem dado clínico, por isso segue a
     * permissão da agenda e a recepção também a acompanha.
     */
    public function viewQueue(User $user): bool
    {
        return $user->hasPermission(Permission::AgendaView);
    }

    public function view(User $user, Treatment $treatment): bool
    {
        return $user->hasPermission(Permission::RecordsView);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::RecordsManage);
    }

    public function update(User $user, Treatment $treatment): bool
    {
        return $treatment->isDraft() && $this->isResponsibleFor($user, $treatment);
    }

    public function finalize(User $user, Treatment $treatment): bool
    {
        return $treatment->isDraft() && $this->isResponsibleFor($user, $treatment);
    }

    /**
     * Descartar um rascunho. Atendimento finalizado não é descartado.
     */
    public function delete(User $user, Treatment $treatment): bool
    {
        return $treatment->isDraft() && $this->isResponsibleFor($user, $treatment);
    }

    /**
     * Enviar fotos e documentos. Em atendimento finalizado qualquer
     * profissional pode acrescentar, por exemplo as fotos do retorno.
     */
    public function attach(User $user, Treatment $treatment): bool
    {
        return $treatment->isDraft()
            ? $this->isResponsibleFor($user, $treatment)
            : $user->hasPermission(Permission::RecordsManage);
    }

    /**
     * Remover fotos e documentos. Em atendimento finalizado, só o administrador.
     */
    public function detach(User $user, Treatment $treatment): bool
    {
        return $treatment->isDraft() ? $this->isResponsibleFor($user, $treatment) : $user->isAdmin();
    }

    /**
     * Registrar evolução ou adendo: só depois de finalizado. Antes disso, edita-se o próprio registro.
     */
    public function addNote(User $user, Treatment $treatment): bool
    {
        return $treatment->isFinalized() && $user->hasPermission(Permission::RecordsManage);
    }

    private function isResponsibleFor(User $user, Treatment $treatment): bool
    {
        if (! $user->hasPermission(Permission::RecordsManage)) {
            return false;
        }

        return $user->isAdmin()
            || $treatment->professional_id === $user->id
            || $treatment->created_by === $user->id;
    }
}
