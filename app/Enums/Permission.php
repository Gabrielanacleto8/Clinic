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

    case AgendaView = 'agenda.view';
    case AgendaManage = 'agenda.manage';

    case ProceduresView = 'procedures.view';
    case ProceduresManage = 'procedures.manage';

    case StockView = 'stock.view';
    case StockManage = 'stock.manage';
    case StockAdjust = 'stock.adjust';

    case FinanceView = 'finance.view';
    case FinanceManage = 'finance.manage';

    public function label(): string
    {
        return match ($this) {
            self::PatientsView => 'Ver o cadastro de pacientes',
            self::PatientsManage => 'Cadastrar e editar pacientes',
            self::PatientsArchive => 'Arquivar e restaurar pacientes',
            self::RecordsView => 'Ver ficha de saúde, atendimentos, fotos e documentos',
            self::RecordsManage => 'Registrar atendimentos e preencher a ficha de saúde',
            self::AgendaView => 'Ver a agenda',
            self::AgendaManage => 'Agendar, remarcar, cancelar e marcar presença ou falta',
            self::ProceduresView => 'Ver o catálogo de procedimentos',
            self::ProceduresManage => 'Cadastrar e editar procedimentos',
            self::StockView => 'Ver produtos, lotes e movimentações',
            self::StockManage => 'Cadastrar produtos e lançar entradas, saídas e perdas',
            self::StockAdjust => 'Fazer inventário e ajustar saldos de estoque',
            self::FinanceView => 'Ver lançamentos e totais financeiros',
            self::FinanceManage => 'Lançar receitas e despesas e registrar pagamentos',
        };
    }

    /**
     * Módulo a que a permissão pertence, como aparece na tela de permissões.
     */
    public function module(): string
    {
        return match ($this) {
            self::PatientsView, self::PatientsManage, self::PatientsArchive => 'Pacientes',
            self::RecordsView, self::RecordsManage => 'Prontuário',
            self::AgendaView, self::AgendaManage => 'Agenda',
            self::ProceduresView, self::ProceduresManage => 'Procedimentos',
            self::StockView, self::StockManage, self::StockAdjust => 'Estoque',
            self::FinanceView, self::FinanceManage => 'Financeiro',
        };
    }

    /**
     * Permissão sem a qual esta não faz sentido. Conceder uma concede a outra.
     */
    public function requires(): ?self
    {
        return match ($this) {
            self::PatientsManage, self::RecordsView => self::PatientsView,
            self::PatientsArchive => self::PatientsManage,
            self::RecordsManage => self::RecordsView,
            self::AgendaManage => self::AgendaView,
            self::ProceduresManage => self::ProceduresView,
            self::StockManage => self::StockView,
            self::StockAdjust => self::StockManage,
            self::FinanceManage => self::FinanceView,
            default => null,
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
                self::StockView,
            ],
            UserRole::Receptionist => [
                self::PatientsView, self::PatientsManage,
                self::AgendaView, self::AgendaManage,
            ],
            UserRole::Admin => self::cases(),
        };
    }
}
