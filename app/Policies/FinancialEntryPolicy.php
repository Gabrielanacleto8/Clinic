<?php

namespace App\Policies;

use App\Enums\FinancialEntryType;
use App\Enums\Permission;
use App\Models\FinancialEntry;
use App\Models\User;

class FinancialEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::FinanceView);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::FinanceManage);
    }

    /**
     * Desfazer o pagamento de uma parcela.
     */
    public function settle(User $user): bool
    {
        return $user->hasPermission(Permission::FinanceManage);
    }

    /**
     * Registrar o recebimento de uma parcela. Receita de paciente pode ser
     * recebida pela recepção, sem acesso ao resto do financeiro; despesa
     * continua com quem lança.
     */
    public function receive(User $user, FinancialEntry $entry): bool
    {
        return $entry->type === FinancialEntryType::Income
            ? $user->hasPermission(Permission::FinanceReceive)
            : $user->hasPermission(Permission::FinanceManage);
    }

    /**
     * Lançamento gerado por atendimento ou por orçamento aprovado faz parte do
     * histórico deles e não é excluído por aqui. Com parcela paga, o pagamento é desfeito
     * antes: excluir sumiria com dinheiro já contado em meses fechados.
     */
    public function delete(User $user, FinancialEntry $entry): bool
    {
        return $user->hasPermission(Permission::FinanceManage) && ! $entry->isGenerated() && ! $entry->hasPaidInstallment();
    }

    /**
     * Editar descrição, categoria e vencimentos de um lançamento manual.
     * Valor e parcelas não mudam: exclui e relança.
     */
    public function update(User $user, FinancialEntry $entry): bool
    {
        return $user->hasPermission(Permission::FinanceManage) && ! $entry->isGenerated();
    }

    /**
     * Ajustar forma de pagamento, parcelas e vencimento da cobrança de um
     * atendimento ou de um pacote, enquanto nada foi recebido. O registro de origem não muda.
     */
    public function adjustCharge(User $user, FinancialEntry $entry): bool
    {
        return $user->hasPermission(Permission::FinanceManage) && $entry->isGenerated() && ! $entry->hasPaidInstallment();
    }

    /**
     * Ver o caixa do dia: quem fecha e quem vê o financeiro.
     */
    public function viewCash(User $user): bool
    {
        return $user->hasPermission(Permission::FinanceCash) || $user->hasPermission(Permission::FinanceView);
    }

    /**
     * Fechar o caixa do dia: conferir o recebido em cada forma de pagamento.
     */
    public function closeCash(User $user): bool
    {
        return $user->hasPermission(Permission::FinanceCash);
    }

    /**
     * Reabrir um dia fechado libera alterar pagamentos já conferidos: só o administrador.
     */
    public function reopenCash(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Quem fecha o caixa sem ver o financeiro: o menu leva direto ao caixa do dia.
     */
    public function cashOnly(User $user): bool
    {
        return $user->hasPermission(Permission::FinanceCash) && ! $user->hasPermission(Permission::FinanceView);
    }

    /**
     * Conferir recebimentos no extrato e registrar a taxa da operadora.
     */
    public function reconcile(User $user): bool
    {
        return $user->hasPermission(Permission::FinanceManage);
    }

    /**
     * Devolver ao paciente parte do que ele pagou. Só cobrança de paciente.
     */
    public function refund(User $user, FinancialEntry $entry): bool
    {
        return $user->hasPermission(Permission::FinanceManage) && $entry->isPatientCharge();
    }

    /**
     * Despesas recorrentes, regras de comissão e repasses.
     */
    public function manage(User $user): bool
    {
        return $user->hasPermission(Permission::FinanceManage);
    }
}
