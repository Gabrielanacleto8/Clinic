<?php

namespace App\Support;

use App\Enums\AppointmentStatus;
use App\Enums\FinancialCategory;
use App\Enums\FinancialEntryType;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\RefundReason;
use App\Enums\StockMovementType;
use App\Enums\UserRole;
use App\Models\AuditLog;

/**
 * Traduz um registro de auditoria para quem não é técnico: o tipo do
 * registro, as chaves dos detalhes e os valores de enum. Os detalhes só
 * têm identificadores e nomes de campos, então tudo pode ser exibido.
 */
final class AuditDescription
{
    private const SUBJECTS = [
        'appointment' => 'Agendamento',
        'audit_log' => 'Registro de auditoria',
        'cash_closing' => 'Fechamento de caixa',
        'commission_payout' => 'Repasse de comissão',
        'clinic_profile' => 'Dados da clínica',
        'financial_entry' => 'Lançamento',
        'financial_installment' => 'Parcela',
        'patient' => 'Paciente',
        'patient_anamnesis' => 'Ficha de saúde',
        'patient_anamnesis_version' => 'Versão da ficha de saúde',
        'patient_attachment' => 'Anexo',
        'procedure' => 'Procedimento',
        'recurring_expense' => 'Despesa recorrente',
        'product' => 'Produto',
        'product_batch' => 'Lote',
        'stock_movement' => 'Movimentação',
        'stock_unit' => 'Unidade',
        'treatment' => 'Atendimento',
        'treatment_note' => 'Evolução',
        'treatment_plan' => 'Orçamento',
        'treatment_plan_item' => 'Item de orçamento',
        'treatment_product' => 'Produto do atendimento',
        'user' => 'Usuário',
    ];

    private const KEYS = [
        'patient_id' => 'Paciente',
        'treatment_id' => 'Atendimento',
        'appointment_id' => 'Agendamento',
        'financial_entry_id' => 'Lançamento',
        'product_id' => 'Produto',
        'product_batch_id' => 'Lote',
        'stock_unit_id' => 'Unidade',
        'professional_id' => 'Profissional',
        'fields' => 'Campos alterados',
        'type' => 'Tipo',
        'category' => 'Categoria',
        'payment_method' => 'Forma de pagamento',
        'amount' => 'Valor',
        'total' => 'Total',
        'installments' => 'Parcelas',
        'paid_at' => 'Data do pagamento',
        'issued_on' => 'Data do lançamento',
        'first_due_date' => 'Primeiro vencimento',
        'from' => 'De',
        'to' => 'Para',
        'price' => 'Valor',
        'price_from' => 'Valor anterior',
        'price_to' => 'Valor novo',
        'role' => 'Perfil',
        'added' => 'Concedidas',
        'removed' => 'Retiradas',
        'reason' => 'Motivo',
        'view' => 'Visão',
        'context' => 'Contexto',
        'tab' => 'Aba',
        'filters' => 'Filtros usados',
        'status' => 'Situação',
        'charged' => 'Com cobrança',
        'products' => 'Produtos',
        'stock_movements_reversed' => 'Movimentações estornadas',
        'treatments' => 'Atendimentos',
        'via' => 'Por',
        'month' => 'Mês',
        'patient_filter' => 'Filtro por paciente',
        'source' => 'Origem',
        'scope' => 'Escopo',
        'remember' => 'Lembrar de mim',
        'archived' => 'Arquivados',
        'version_id' => 'Versão',
        'procedure_id' => 'Procedimento',
        'rows' => 'Linhas exportadas',
        'items' => 'Itens',
        'plan_item_id' => 'Sessão de pacote (item)',
        'day' => 'Dia',
        'lines' => 'Formas de pagamento',
        'expected' => 'Esperado',
        'counted' => 'Contado',
        'difference' => 'Diferença',
        'fee' => 'Taxa',
        'reconciled_on' => 'Data no extrato',
        'refund_of_id' => 'Lançamento estornado',
        'discount' => 'Desconto',
        'rate' => 'Comissão (%)',
        'rates' => 'Regras de comissão',
        'limits' => 'Desconto máximo (%)',
        'until' => 'Atendimentos até',
        'count' => 'Quantidade',
        'months' => 'Meses',
        'recurring_expense_id' => 'Despesa recorrente',
        'commission_payout_id' => 'Repasse de comissão',
    ];

    private const FIELDS = [
        'name' => 'nome', 'cpf' => 'CPF', 'birth_date' => 'nascimento', 'sex' => 'sexo', 'phone' => 'telefone', 'email' => 'e-mail',
        'zip_code' => 'CEP', 'street' => 'logradouro', 'number' => 'número', 'complement' => 'complemento', 'district' => 'bairro',
        'city' => 'cidade', 'state' => 'UF', 'notes' => 'observações', 'image_use_consented_at' => 'autorização de uso de imagem',
        'description' => 'descrição', 'category' => 'categoria', 'price' => 'valor', 'duration_minutes' => 'duração', 'is_active' => 'situação',
        'payment_method' => 'forma de pagamento', 'due_date' => 'vencimento', 'installments' => 'parcelas', 'first_due_date' => 'primeiro vencimento',
        'role' => 'perfil', 'password' => 'senha', 'scheduled_at' => 'data e hora', 'professional_id' => 'profissional', 'procedure_id' => 'procedimento',
        'allergies' => 'alergias', 'medications' => 'medicamentos', 'health_conditions' => 'condições de saúde', 'previous_procedures' => 'procedimentos anteriores',
        'pregnant_or_lactating' => 'gestante ou lactante', 'expires_on' => 'validade', 'manufactured_on' => 'fabricação', 'products' => 'produtos típicos',
        'minimum_stock' => 'estoque mínimo', 'cost' => 'custo', 'unit' => 'unidade', 'unit_content' => 'conteúdo', 'brand' => 'marca',
        'manufacturer' => 'fabricante', 'supplier' => 'fornecedor', 'code' => 'código', 'hours_after_opening' => 'prazo depois de aberto',
        'multi_patient_use' => 'uso em vários pacientes', 'legal_name' => 'razão social', 'cnpj' => 'CNPJ', 'address' => 'endereço',
        'technical_lead' => 'responsável técnico', 'professional_registration' => 'registro profissional', 'consent_template' => 'termo de consentimento',
        'valid_until' => 'validade',
        'amount' => 'valor', 'due_day' => 'dia do vencimento', 'starts_on' => 'início', 'ends_on' => 'fim',
    ];

    private const REASONS = [
        'patient_archived' => 'paciente arquivado',
        'treatment_voided' => 'atendimento anulado',
    ];

    /**
     * "Lançamento #42" ou "—".
     */
    public static function subject(AuditLog $log): string
    {
        if ($log->auditable_type === null) {
            return '—';
        }

        return (self::SUBJECTS[$log->auditable_type] ?? $log->auditable_type).' #'.$log->auditable_id;
    }

    /**
     * Detalhes legíveis: rótulo => texto.
     *
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, string>
     */
    public static function details(?array $metadata): array
    {
        $lines = [];

        foreach ($metadata ?? [] as $key => $value) {
            $lines[self::KEYS[$key] ?? $key] = self::value($key, $value);
        }

        return $lines;
    }

    private static function value(string $key, mixed $value): string
    {
        if (is_array($value)) {
            // Lista de campos, de permissões ou um par de/para com várias chaves.
            if (array_is_list($value)) {
                return implode(', ', array_map(fn ($item) => is_scalar($item) ? self::scalar($key, $item) : json_encode($item, JSON_UNESCAPED_UNICODE), $value));
            }

            return implode('; ', array_map(fn ($k, $v) => (self::KEYS[$k] ?? $k).': '.self::scalar($k, $v), array_keys($value), $value));
        }

        return self::scalar($key, $value);
    }

    private static function scalar(string $key, mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'sim' : 'não';
        }

        $text = (string) $value;

        return match ($key) {
            'fields' => self::FIELDS[$text] ?? $text,
            'added', 'removed' => Permission::tryFrom($text)?->label() ?? $text,
            'role' => UserRole::tryFrom($text)?->label() ?? $text,
            'type' => FinancialEntryType::tryFrom($text)?->label() ?? StockMovementType::tryFrom($text)?->label() ?? $text,
            'category' => FinancialCategory::tryFrom($text)?->label() ?? $text,
            'payment_method' => PaymentMethod::tryFrom($text)?->label() ?? $text,
            'from', 'to', 'status' => AppointmentStatus::tryFrom($text)?->label() ?? PaymentMethod::tryFrom($text)?->label() ?? $text,
            'reason' => self::REASONS[$text] ?? RefundReason::tryFrom($text)?->label() ?? $text,
            'amount', 'total', 'price', 'price_from', 'price_to', 'expected', 'counted', 'difference', 'fee', 'discount' => is_numeric($text) ? LocalizedDecimal::money($text) : $text,
            'paid_at', 'issued_on', 'first_due_date', 'day', 'reconciled_on', 'until' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) ? substr($text, 8, 2).'/'.substr($text, 5, 2).'/'.substr($text, 0, 4) : $text,
            default => $text,
        };
    }
}
