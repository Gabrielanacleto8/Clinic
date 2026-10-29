<?php

namespace App\Enums;

/**
 * Permissões que o administrador pode conceder a cada perfil.
 *
 * A lista vive no código porque só muda quando o sistema ganha um módulo.
 * Gestão de usuários e de permissões não está aqui: é sempre exclusiva do
 * administrador, para que o controle de acesso não possa ser delegado.
 */
enum Permission: string
{
    case PatientsView = 'patients.view';
    case PatientsManage = 'patients.manage';
    case PatientsArchive = 'patients.archive';

    case RecordsView = 'records.view';
    case RecordsManage = 'records.manage';
    case RecordsVoid = 'records.void';

    case AgendaView = 'agenda.view';
    case AgendaManage = 'agenda.manage';

    case ProceduresView = 'procedures.view';
    case ProceduresManage = 'procedures.manage';

    case StockView = 'stock.view';
    case StockManage = 'stock.manage';
    case StockAdjust = 'stock.adjust';
    case StockDiscard = 'stock.discard';

    case FinanceView = 'finance.view';
    case FinanceManage = 'finance.manage';
    case FinanceReceive = 'finance.receive';
    case FinanceCash = 'finance.cash';
    case PlansView = 'plans.view';
    case PlansManage = 'plans.manage';

    public function label(): string
    {
        return match ($this) {
            self::PatientsView => 'Ver o cadastro de pacientes',
            self::PatientsManage => 'Cadastrar e editar pacientes',
            self::PatientsArchive => 'Arquivar e restaurar pacientes',
            self::RecordsView => 'Ver ficha de saúde, atendimentos, fotos e documentos',
            self::RecordsManage => 'Registrar atendimentos e preencher a ficha de saúde',
            self::RecordsVoid => 'Anular atendimento registrado com erro (devolve estoque e exclui a cobrança; inclui lançar no financeiro)',
            self::AgendaView => 'Ver a agenda',
            self::AgendaManage => 'Agendar, remarcar, cancelar e marcar presença ou falta',
            self::ProceduresView => 'Ver o catálogo de procedimentos',
            self::ProceduresManage => 'Cadastrar e editar procedimentos',
            self::StockView => 'Ver produtos, lotes e movimentações',
            self::StockManage => 'Cadastrar produtos e lançar entradas, saídas e perdas',
            self::StockAdjust => 'Fazer inventário e ajustar saldos de estoque',
            self::StockDiscard => 'Descartar unidades abertas e lotes vencidos',
            self::FinanceView => 'Ver todo o financeiro, inclusive despesas e salários',
            self::FinanceManage => 'Lançar e excluir receitas e despesas, registrar e desfazer pagamentos',
            self::FinanceReceive => 'Registrar o recebimento de cobranças de pacientes',
            self::FinanceCash => 'Fechar o caixa do dia (conferir o recebido em cada forma de pagamento)',
            self::PlansView => 'Ver orçamentos e pacotes dos pacientes',
            self::PlansManage => 'Criar orçamentos, converter em venda (gera a cobrança) e encerrar pacotes',
        };
    }

    /**
     * Módulo a que a permissão pertence, como aparece na tela de permissões.
     */
    public function module(): string
    {
        return match ($this) {
            self::PatientsView, self::PatientsManage, self::PatientsArchive => 'Pacientes',
            self::RecordsView, self::RecordsManage, self::RecordsVoid => 'Prontuário',
            self::AgendaView, self::AgendaManage => 'Agenda',
            self::ProceduresView, self::ProceduresManage => 'Procedimentos',
            self::StockView, self::StockManage, self::StockAdjust, self::StockDiscard => 'Estoque',
            self::FinanceView, self::FinanceManage, self::FinanceReceive, self::FinanceCash => 'Financeiro',
            self::PlansView, self::PlansManage => 'Orçamentos e pacotes',
        };
    }

    /**
     * Permissões sem as quais esta não faz sentido. Conceder uma concede as outras.
     *
     * @return list<self>
     */
    public function requires(): array
    {
        return match ($this) {
            self::PatientsManage, self::RecordsView => [self::PatientsView],
            self::PatientsArchive => [self::PatientsManage],
            self::RecordsManage => [self::RecordsView],
            // Anular exclui a cobrança do atendimento: é também decisão do financeiro.
            self::RecordsVoid => [self::RecordsManage, self::FinanceManage],
            // Agendar é escolher o paciente na busca do cadastro.
            self::AgendaManage => [self::AgendaView, self::PatientsView],
            self::ProceduresManage => [self::ProceduresView],
            self::StockManage => [self::StockView],
            self::StockAdjust => [self::StockManage],
            self::StockDiscard => [self::StockView],
            self::FinanceManage => [self::FinanceView, self::FinanceReceive],
            // Receber é dar baixa na cobrança de um paciente: não exige ver o financeiro.
            self::FinanceReceive => [self::PatientsView],
            // Fecha o caixa quem recebe: a conferência é do que entrou.
            self::FinanceCash => [self::FinanceReceive],
            self::PlansView => [self::PatientsView],
            self::PlansManage => [self::PlansView],
            default => [],
        };
    }

    /**
     * Acesso padrão de cada perfil configurável, usado na instalação.
     *
     * @return list<self>
     */
    public static function defaultsFor(UserRole $role): array
    {
        return match ($role) {
            UserRole::Professional => [
                self::PatientsView, self::PatientsManage,
                self::RecordsView, self::RecordsManage,
                self::AgendaView, self::AgendaManage,
                self::ProceduresView,
                self::StockView, self::StockDiscard,
                self::PlansView, self::PlansManage,
            ],
            UserRole::Receptionist => [
                self::PatientsView, self::PatientsManage,
                self::AgendaView, self::AgendaManage,
                self::FinanceReceive, self::FinanceCash,
                self::PlansView, self::PlansManage,
            ],
            UserRole::Admin => self::cases(),
        };
    }
}
