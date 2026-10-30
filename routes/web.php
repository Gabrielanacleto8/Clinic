<?php

use App\Http\Controllers\AgendaController;
use App\Http\Controllers\AnamnesisController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\CashClosingController;
use App\Http\Controllers\CashFlowController;
use App\Http\Controllers\CepLookupController;
use App\Http\Controllers\ChecklistItemController;
use App\Http\Controllers\ClinicIdentityController;
use App\Http\Controllers\ClinicSettingsController;
use App\Http\Controllers\CommissionController;
use App\Http\Controllers\CommissionPayoutController;
use App\Http\Controllers\CommissionRateController;
use App\Http\Controllers\ConsentTermController;
use App\Http\Controllers\FinanceAccountsController;
use App\Http\Controllers\FinanceAnalysisController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\FinanceDelinquencyController;
use App\Http\Controllers\FinanceExportController;
use App\Http\Controllers\FinanceMarginController;
use App\Http\Controllers\FinancialEntryController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InstallmentPaymentController;
use App\Http\Controllers\PatientAvatarController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PatientPhotoController;
use App\Http\Controllers\PatientRecordController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\ProcedureController;
use App\Http\Controllers\ProductBatchController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReconciliationController;
use App\Http\Controllers\RecurringExpenseController;
use App\Http\Controllers\RefundController;
use App\Http\Controllers\StockEntryController;
use App\Http\Controllers\StockInventoryController;
use App\Http\Controllers\StockInvoiceController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\StockUnitController;
use App\Http\Controllers\SubtaskController;
use App\Http\Controllers\TaskAttachmentController;
use App\Http\Controllers\TaskColumnController;
use App\Http\Controllers\TaskCommentController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskLabelController;
use App\Http\Controllers\TreatmentChargeController;
use App\Http\Controllers\TreatmentController;
use App\Http\Controllers\TreatmentDocumentController;
use App\Http\Controllers\TreatmentNoteController;
use App\Http\Controllers\TreatmentPhotoController;
use App\Http\Controllers\TreatmentPlanController;
use App\Http\Controllers\TreatmentPlanSessionController;
use App\Http\Controllers\TreatmentPlanStatusController;
use App\Http\Controllers\UserController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::resourceVerbs(['create' => 'novo', 'edit' => 'editar']);

// Marca da clínica e aplicativo instalável: o navegador busca sem a sessão.
// Respostas públicas e em cache: sem sessão nem cookies, para nenhum proxy
// guardar o cookie de alguém junto com a imagem.
Route::withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, AddQueuedCookiesToResponse::class, EncryptCookies::class, PreventRequestForgery::class])
    ->group(function () {
        Route::get('manifest.webmanifest', [ClinicIdentityController::class, 'manifest'])->name('pwa.manifest');
        Route::get('clinica/logo', [ClinicIdentityController::class, 'logo'])->name('clinic.logo');
        Route::get('clinica/icone-{size}.png', [ClinicIdentityController::class, 'icon'])->whereIn('size', ['180', '192', '512'])->name('clinic.icon');
    });

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:login');
});

Route::middleware(['auth', 'auth.session', 'active'])->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    // Endereço pelo CEP, consultado pelo servidor (a CSP não deixa o navegador falar com serviços externos).
    Route::get('cep/{cep}', CepLookupController::class)->where('cep', '[0-9]{8}')->middleware('throttle:30,1')->name('cep.lookup');

    // Mantém a sessão viva enquanto alguém digita um formulário longo (ver app.js).
    Route::get('sessao', fn () => response()->noContent())->middleware('throttle:20,1')->name('session.keepalive');

    Route::get('senha', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('senha', [PasswordController::class, 'update'])->name('password.update');

    // Tudo abaixo exige que a senha provisória já tenha sido trocada.
    Route::middleware('password.changed')->group(function () {
        Route::get('/', [HomeController::class, 'index'])->name('home');

        Route::get('perfil', [ProfileController::class, 'show'])->name('profile.show');
        Route::post('perfil/aparencia', [ProfileController::class, 'updateTheme'])->name('profile.theme');
        Route::get('busca', GlobalSearchController::class)->middleware('throttle:60,1')->name('search');

        Route::get('configuracoes', [ClinicSettingsController::class, 'edit'])->name('clinic.edit');
        Route::put('configuracoes', [ClinicSettingsController::class, 'update'])->name('clinic.update');
        Route::post('configuracoes/logo', [ClinicSettingsController::class, 'updateLogo'])->name('clinic.logo.update');
        Route::delete('configuracoes/logo', [ClinicSettingsController::class, 'destroyLogo'])->name('clinic.logo.destroy');

        Route::get('agenda', [AgendaController::class, 'index'])->name('agenda.index');
        Route::get('agenda/ocupacao', [AgendaController::class, 'occupancy'])->middleware('throttle:60,1')->name('agenda.occupancy');

        Route::resource('agendamentos', AppointmentController::class)
            ->parameters(['agendamentos' => 'appointment'])
            ->names('appointments')
            ->only(['create', 'store', 'edit', 'update']);

        Route::patch('agendamentos/{appointment}/situacao', [AppointmentController::class, 'status'])->name('appointments.status');

        // Antes do resource de pacientes, para "buscar" não ser lido como um id.
        Route::get('pacientes/buscar', [PatientController::class, 'search'])
            ->middleware('throttle:60,1')
            ->name('patients.search');

        Route::resource('pacientes', PatientController::class)
            ->parameters(['pacientes' => 'patient'])
            ->names('patients');

        Route::patch('pacientes/{patient}/restaurar', [PatientController::class, 'restore'])
            ->withTrashed()
            ->name('patients.restore');

        // Atendimentos registrados. O diálogo "Registrar atendimento" envia para
        // cá: o paciente é escolhido no próprio formulário.
        Route::get('atendimentos', [TreatmentController::class, 'index'])->name('treatments.index');
        Route::post('atendimentos', [TreatmentController::class, 'storeForChosenPatient'])->name('treatments.store');

        // Prontuário. scopeBindings garante o encadeamento: o atendimento tem
        // de ser daquele paciente e a foto daquele atendimento, ou dá 404.
        Route::prefix('pacientes/{patient}')->name('patients.')->scopeBindings()->group(function () {
            // Foto de identificação (avatar): segue o acesso ao cadastro, não ao prontuário.
            Route::get('avatar', [PatientAvatarController::class, 'show'])->name('avatar.show');
            Route::post('avatar', [PatientAvatarController::class, 'update'])->name('avatar.update');
            Route::delete('avatar', [PatientAvatarController::class, 'destroy'])->name('avatar.destroy');

            // Arquivado também: o prontuário segue consultável sem restaurar o cadastro.
            Route::get('prontuario', [PatientRecordController::class, 'show'])->name('record')->withTrashed();
            Route::get('termo-de-consentimento', [ConsentTermController::class, 'show'])->name('consent-term');

            // Orçamentos e pacotes do paciente. Criar fica no módulo (/orcamentos/novo), com o paciente escolhido no formulário.
            Route::get('orcamentos/{treatmentPlan}', [TreatmentPlanController::class, 'show'])->name('plans.show');
            // Detalhes para a janela da lista de orçamentos.
            Route::get('orcamentos/{treatmentPlan}/resumo', [TreatmentPlanController::class, 'summary'])->name('plans.summary');
            Route::get('orcamentos/{treatmentPlan}/editar', [TreatmentPlanController::class, 'edit'])->name('plans.edit');
            Route::put('orcamentos/{treatmentPlan}', [TreatmentPlanController::class, 'update'])->name('plans.update');
            Route::get('orcamentos/{treatmentPlan}/imprimir', [TreatmentPlanController::class, 'print'])->name('plans.print');
            Route::post('orcamentos/{treatmentPlan}/aprovacao', [TreatmentPlanStatusController::class, 'approve'])->name('plans.approve');
            Route::post('orcamentos/{treatmentPlan}/recusa', [TreatmentPlanStatusController::class, 'decline'])->name('plans.decline');
            Route::post('orcamentos/{treatmentPlan}/encerramento', [TreatmentPlanStatusController::class, 'close'])->name('plans.close');
            // Sessões de pacote que o paciente ainda pode usar, para o registro do atendimento.
            Route::get('sessoes-de-pacote', [TreatmentPlanSessionController::class, 'index'])->middleware('throttle:60,1')->name('plan-sessions');

            Route::get('ficha-de-saude', [AnamnesisController::class, 'edit'])->name('anamnesis.edit');
            Route::put('ficha-de-saude', [AnamnesisController::class, 'update'])->name('anamnesis.update');
            Route::get('ficha-de-saude/versoes/{anamnesisVersion}', [AnamnesisController::class, 'version'])->name('anamnesis.version');
            Route::get('alertas-de-saude', [AnamnesisController::class, 'alerts'])->middleware('throttle:60,1')->name('health-alerts');

            // Fotos: o envio pela ficha é de fotos avulsas; o de atendimento fica em
            // atendimentos/{treatment}/fotos. Ler e remover é sempre por aqui, para qualquer foto do paciente.
            Route::post('fotos', [PatientPhotoController::class, 'store'])->name('photos.store');
            // Antes de fotos/{photo}, para "comparar" não ser lido como um id.
            Route::get('fotos/comparar', [PatientPhotoController::class, 'compare'])->name('photos.compare');
            Route::get('fotos/{photo}', [PatientPhotoController::class, 'show'])->name('photos.show');
            Route::delete('fotos/{photo}', [PatientPhotoController::class, 'destroy'])->name('photos.destroy');

            Route::resource('atendimentos', TreatmentController::class)
                ->parameters(['atendimentos' => 'treatment'])
                ->names('treatments')
                ->only(['create', 'store', 'show']);

            // Resumo do atendimento para a janela da lista de atendimentos.
            Route::get('atendimentos/{treatment}/resumo', [TreatmentController::class, 'summary'])->name('treatments.summary');
            Route::post('atendimentos/{treatment}/anulacao', [TreatmentController::class, 'void'])->name('treatments.void');

            Route::post('atendimentos/{treatment}/fotos', [TreatmentPhotoController::class, 'store'])->name('treatments.photos.store');

            Route::post('atendimentos/{treatment}/documentos', [TreatmentDocumentController::class, 'store'])->name('treatments.documents.store');
            Route::get('atendimentos/{treatment}/documentos/{document}', [TreatmentDocumentController::class, 'show'])->name('treatments.documents.show');
            Route::delete('atendimentos/{treatment}/documentos/{document}', [TreatmentDocumentController::class, 'destroy'])->name('treatments.documents.destroy');

            Route::post('atendimentos/{treatment}/evolucoes', [TreatmentNoteController::class, 'store'])->name('treatments.notes.store');
        });

        Route::get('orcamentos', [TreatmentPlanController::class, 'index'])->name('plans.index');
        Route::get('orcamentos/novo', [TreatmentPlanController::class, 'create'])->name('plans.create');
        Route::post('orcamentos', [TreatmentPlanController::class, 'store'])->name('plans.store');

        Route::resource('procedimentos', ProcedureController::class)
            ->parameters(['procedimentos' => 'procedure'])
            ->names('procedures')
            ->only(['index', 'create', 'store', 'edit', 'update']);

        // Abas do estoque. Antes do resource de produtos, para não serem lidas como um id.
        Route::get('estoque/entradas', [StockEntryController::class, 'index'])->name('stock.entries.index');
        Route::post('estoque/entradas', [StockEntryController::class, 'store'])->name('stock.entries.store');
        Route::get('estoque/movimentacoes', [StockMovementController::class, 'index'])->name('stock.movements.index');
        Route::get('estoque/lotes', [ProductBatchController::class, 'index'])->name('stock.batches.index');
        Route::get('estoque/lotes/{batch}', [ProductBatchController::class, 'show'])->name('stock.batches.show');
        Route::patch('estoque/lotes/{batch}', [ProductBatchController::class, 'update'])->name('stock.batches.update');
        Route::post('estoque/lotes/{batch}/baixa-vencido', [ProductBatchController::class, 'writeOff'])->name('stock.batches.write-off');
        Route::patch('estoque/movimentacoes/{movement}/custo', [StockMovementController::class, 'updateCost'])->name('stock.movements.cost');
        // Unidades físicas de produto fracionado.
        Route::get('estoque/unidades', [StockUnitController::class, 'index'])->name('stock.units.index');
        Route::post('estoque/lotes/{batch}/unidades', [StockUnitController::class, 'store'])->name('stock.units.store');
        Route::get('estoque/unidades/{unit}', [StockUnitController::class, 'show'])->name('stock.units.show');
        Route::post('estoque/unidades/{unit}/descarte', [StockUnitController::class, 'discard'])->name('stock.units.discard');
        Route::post('estoque/nota-fiscal', [StockInvoiceController::class, 'store'])->name('stock.invoice.store');
        Route::get('estoque/nota-fiscal', [StockInvoiceController::class, 'edit'])->name('stock.invoice.edit');
        Route::put('estoque/nota-fiscal', [StockInvoiceController::class, 'update'])->name('stock.invoice.update');
        Route::delete('estoque/nota-fiscal', [StockInvoiceController::class, 'destroy'])->name('stock.invoice.destroy');
        Route::get('estoque/inventario', [StockInventoryController::class, 'edit'])->name('stock.inventory.edit');
        Route::put('estoque/inventario', [StockInventoryController::class, 'update'])->name('stock.inventory.update');

        Route::resource('estoque', ProductController::class)
            ->parameters(['estoque' => 'product'])
            ->names('products')
            ->except(['destroy']);

        Route::post('estoque/{product}/movimentacoes', [StockMovementController::class, 'store'])->name('products.movements.store');
        Route::get('estoque/{product}/resumo', [ProductController::class, 'summary'])->name('products.summary');

        // Quadro de tarefas. Colunas e etiquetas antes de {task}, para não serem lidas como id.
        Route::get('tarefas', [TaskController::class, 'index'])->name('tasks.index');
        Route::post('tarefas', [TaskController::class, 'store'])->name('tasks.store');
        Route::get('tarefas/colunas', [TaskColumnController::class, 'index'])->name('tasks.columns.index');
        Route::post('tarefas/colunas', [TaskColumnController::class, 'store'])->name('tasks.columns.store');
        Route::put('tarefas/colunas/{column}', [TaskColumnController::class, 'update'])->name('tasks.columns.update');
        Route::delete('tarefas/colunas/{column}', [TaskColumnController::class, 'destroy'])->name('tasks.columns.destroy');
        Route::get('tarefas/etiquetas', [TaskLabelController::class, 'index'])->name('tasks.labels.index');
        Route::post('tarefas/etiquetas', [TaskLabelController::class, 'store'])->name('tasks.labels.store');
        Route::put('tarefas/etiquetas/{label}', [TaskLabelController::class, 'update'])->name('tasks.labels.update');
        Route::delete('tarefas/etiquetas/{label}', [TaskLabelController::class, 'destroy'])->name('tasks.labels.destroy');

        Route::prefix('tarefas/{task}')->name('tasks.')->scopeBindings()->group(function () {
            Route::put('/', [TaskController::class, 'update'])->name('update');
            Route::delete('/', [TaskController::class, 'destroy'])->name('destroy');
            Route::post('mover', [TaskController::class, 'move'])->name('move');
            Route::post('comentarios', [TaskCommentController::class, 'store'])->name('comments.store');
            Route::delete('comentarios/{comment}', [TaskCommentController::class, 'destroy'])->name('comments.destroy');
            Route::post('subtarefas', [SubtaskController::class, 'store'])->name('subtasks.store');
            Route::put('subtarefas/{subtask}', [SubtaskController::class, 'update'])->name('subtasks.update');
            Route::post('subtarefas/{subtask}/concluir', [SubtaskController::class, 'toggle'])->name('subtasks.toggle');
            Route::delete('subtarefas/{subtask}', [SubtaskController::class, 'destroy'])->name('subtasks.destroy');
            Route::post('subtarefas/{subtask}/itens', [ChecklistItemController::class, 'store'])->name('items.store');
            Route::post('subtarefas/{subtask}/itens/{item}/concluir', [ChecklistItemController::class, 'toggle'])->name('items.toggle');
            Route::delete('subtarefas/{subtask}/itens/{item}', [ChecklistItemController::class, 'destroy'])->name('items.destroy');
            Route::post('anexos', [TaskAttachmentController::class, 'store'])->name('attachments.store');
            Route::get('anexos/{attachment}', [TaskAttachmentController::class, 'show'])->name('attachments.show');
            Route::delete('anexos/{attachment}', [TaskAttachmentController::class, 'destroy'])->name('attachments.destroy');
        });

        Route::get('financeiro', [FinanceController::class, 'index'])->name('finance.index');
        Route::get('financeiro/exportar', FinanceExportController::class)->middleware('throttle:10,1')->name('finance.export');
        Route::get('financeiro/margem', FinanceMarginController::class)->name('finance.margin');
        Route::get('financeiro/comparativo', FinanceAnalysisController::class)->name('finance.analysis');
        Route::get('financeiro/a-receber', [FinanceAccountsController::class, 'receivable'])->name('finance.receivable');
        Route::get('financeiro/a-pagar', [FinanceAccountsController::class, 'payable'])->name('finance.payable');
        Route::get('financeiro/inadimplencia', FinanceDelinquencyController::class)->name('finance.delinquency');
        Route::get('financeiro/fluxo-de-caixa', CashFlowController::class)->name('finance.cashflow');
        Route::get('financeiro/caixa', [CashClosingController::class, 'show'])->name('finance.cash.show');
        Route::post('financeiro/caixa', [CashClosingController::class, 'store'])->name('finance.cash.store');
        Route::delete('financeiro/caixa/{closing}', [CashClosingController::class, 'destroy'])->name('finance.cash.destroy');
        Route::get('financeiro/conciliacao', [ReconciliationController::class, 'index'])->name('finance.reconciliation');
        Route::post('financeiro/conciliacao', [ReconciliationController::class, 'store'])->name('finance.reconciliation.store');
        Route::delete('financeiro/conciliacao/{installment}', [ReconciliationController::class, 'destroy'])->name('finance.reconciliation.destroy');
        Route::post('financeiro/estornos', [RefundController::class, 'store'])->name('finance.refunds.store');
        Route::delete('financeiro/estornos/{refund}', [RefundController::class, 'destroy'])->name('finance.refunds.destroy');
        Route::get('financeiro/despesas-fixas', [RecurringExpenseController::class, 'index'])->name('finance.recurring.index');
        Route::post('financeiro/despesas-fixas', [RecurringExpenseController::class, 'store'])->name('finance.recurring.store');
        Route::put('financeiro/despesas-fixas/{recurringExpense}', [RecurringExpenseController::class, 'update'])->name('finance.recurring.update');
        Route::get('financeiro/comissoes', [CommissionController::class, 'index'])->name('finance.commissions.index');
        Route::get('financeiro/comissoes/regras', [CommissionRateController::class, 'edit'])->name('finance.commissions.rates');
        Route::put('financeiro/comissoes/regras', [CommissionRateController::class, 'update'])->name('finance.commissions.rates.update');
        Route::get('financeiro/comissoes/{professional}', [CommissionController::class, 'show'])->name('finance.commissions.show');
        Route::post('financeiro/comissoes/{professional}/repasses', [CommissionPayoutController::class, 'store'])->name('finance.commissions.payouts.store');
        Route::delete('financeiro/repasses/{payout}', [CommissionPayoutController::class, 'destroy'])->name('finance.commissions.payouts.destroy');
        Route::get('financeiro/lancamentos/novo', [FinancialEntryController::class, 'create'])->name('finance.entries.create');
        Route::post('financeiro/lancamentos', [FinancialEntryController::class, 'store'])->name('finance.entries.store');
        Route::get('financeiro/lancamentos/{entry}/editar', [FinancialEntryController::class, 'edit'])->name('finance.entries.edit');
        Route::put('financeiro/lancamentos/{entry}', [FinancialEntryController::class, 'update'])->name('finance.entries.update');
        Route::delete('financeiro/lancamentos/{entry}', [FinancialEntryController::class, 'destroy'])->name('finance.entries.destroy');
        Route::put('financeiro/lancamentos/{entry}/cobranca', [TreatmentChargeController::class, 'update'])->name('finance.entries.charge');
        Route::post('financeiro/pagamentos', [InstallmentPaymentController::class, 'store'])->name('finance.payments.store');
        Route::delete('financeiro/pagamentos/{installment}', [InstallmentPaymentController::class, 'destroy'])->name('finance.payments.destroy');

        // Aba de permissões dentro de usuários. Antes do resource, para "permissoes" não ser lido como um id.
        Route::get('auditoria', [AuditLogController::class, 'index'])->name('audit.index');

        Route::get('usuarios/permissoes', [PermissionController::class, 'edit'])->name('users.permissions.edit');
        Route::put('usuarios/permissoes', [PermissionController::class, 'update'])->name('users.permissions.update');

        Route::resource('usuarios', UserController::class)
            ->parameters(['usuarios' => 'user'])
            ->names('users')
            ->except(['show', 'destroy']);
    });
});
