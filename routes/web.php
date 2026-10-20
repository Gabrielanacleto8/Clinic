<?php

use App\Http\Controllers\AgendaController;
use App\Http\Controllers\AnamnesisController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\FinancialEntryController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InstallmentPaymentController;
use App\Http\Controllers\PatientAvatarController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PatientPhotoController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\ProcedureController;
use App\Http\Controllers\ProductBatchController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\StockEntryController;
use App\Http\Controllers\StockInventoryController;
use App\Http\Controllers\StockInvoiceController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\StockUnitController;
use App\Http\Controllers\TreatmentController;
use App\Http\Controllers\TreatmentDocumentController;
use App\Http\Controllers\TreatmentNoteController;
use App\Http\Controllers\TreatmentPhotoController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::resourceVerbs(['create' => 'novo', 'edit' => 'editar']);

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:login');
});

Route::middleware(['auth', 'auth.session', 'active'])->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('senha', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('senha', [PasswordController::class, 'update'])->name('password.update');

    // Tudo abaixo exige que a senha provisória já tenha sido trocada.
    Route::middleware('password.changed')->group(function () {
        Route::get('/', [HomeController::class, 'index'])->name('home');

        Route::get('agenda', [AgendaController::class, 'index'])->name('agenda.index');

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

            Route::get('ficha-de-saude', [AnamnesisController::class, 'edit'])->name('anamnesis.edit');
            Route::put('ficha-de-saude', [AnamnesisController::class, 'update'])->name('anamnesis.update');
            Route::get('alertas-de-saude', [AnamnesisController::class, 'alerts'])->middleware('throttle:60,1')->name('health-alerts');

            // Fotos: o envio pela ficha é de fotos avulsas; o de atendimento fica em
            // atendimentos/{treatment}/fotos. Ler e remover é sempre por aqui, para qualquer foto do paciente.
            Route::post('fotos', [PatientPhotoController::class, 'store'])->name('photos.store');
            Route::get('fotos/{photo}', [PatientPhotoController::class, 'show'])->name('photos.show');
            Route::delete('fotos/{photo}', [PatientPhotoController::class, 'destroy'])->name('photos.destroy');

            Route::resource('atendimentos', TreatmentController::class)
                ->parameters(['atendimentos' => 'treatment'])
                ->names('treatments')
                ->only(['create', 'store', 'show']);

            // Resumo do atendimento para a janela da lista de atendimentos.
            Route::get('atendimentos/{treatment}/resumo', [TreatmentController::class, 'summary'])->name('treatments.summary');

            Route::post('atendimentos/{treatment}/fotos', [TreatmentPhotoController::class, 'store'])->name('treatments.photos.store');

            Route::post('atendimentos/{treatment}/documentos', [TreatmentDocumentController::class, 'store'])->name('treatments.documents.store');
            Route::get('atendimentos/{treatment}/documentos/{document}', [TreatmentDocumentController::class, 'show'])->name('treatments.documents.show');
            Route::delete('atendimentos/{treatment}/documentos/{document}', [TreatmentDocumentController::class, 'destroy'])->name('treatments.documents.destroy');

            Route::post('atendimentos/{treatment}/evolucoes', [TreatmentNoteController::class, 'store'])->name('treatments.notes.store');
        });

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
        // Unidades físicas de produto fracionado.
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

        Route::get('financeiro', [FinanceController::class, 'index'])->name('finance.index');
        Route::get('financeiro/lancamentos/novo', [FinancialEntryController::class, 'create'])->name('finance.entries.create');
        Route::post('financeiro/lancamentos', [FinancialEntryController::class, 'store'])->name('finance.entries.store');
        Route::delete('financeiro/lancamentos/{entry}', [FinancialEntryController::class, 'destroy'])->name('finance.entries.destroy');
        Route::post('financeiro/pagamentos', [InstallmentPaymentController::class, 'store'])->name('finance.payments.store');
        Route::delete('financeiro/pagamentos/{installment}', [InstallmentPaymentController::class, 'destroy'])->name('finance.payments.destroy');

        // Aba de permissões dentro de usuários. Antes do resource, para "permissoes" não ser lido como um id.
        Route::get('usuarios/permissoes', [PermissionController::class, 'edit'])->name('users.permissions.edit');
        Route::put('usuarios/permissoes', [PermissionController::class, 'update'])->name('users.permissions.update');

        Route::resource('usuarios', UserController::class)
            ->parameters(['usuarios' => 'user'])
            ->names('users')
            ->except(['show', 'destroy']);
    });
});
