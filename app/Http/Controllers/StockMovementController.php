<?php

namespace App\Http\Controllers;

use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Http\Requests\StockMovementRequest;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Services\StockLedger;
use App\Support\LocalizedDecimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockMovementController extends Controller
{
    public function __construct(private readonly StockLedger $ledger) {}

    public function store(StockMovementRequest $request, Product $product): RedirectResponse
    {
        try {
            $message = match ($request->type()) {
                StockMovementType::Entry => $this->enter($request, $product),
                StockMovementType::Exit => $this->withdraw($request),
                StockMovementType::Adjustment => $this->adjust($request),
            };
        } catch (InsufficientStockException $exception) {
            throw ValidationException::withMessages([
                'quantity' => 'Saldo insuficiente no lote '.$exception->batch->batch_code.'. Disponível: '.LocalizedDecimal::quantity($exception->available).'.',
            ]);
        }

        return redirect()->route('products.show', $product)->with('success', $message);
    }

    private function enter(StockMovementRequest $request, Product $product): string
    {
        DB::transaction(function () use ($request, $product): void {
            $this->ledger->enter(
                $this->batchForEntry($request, $product),
                (string) $request->validated('quantity'),
                $request->validated('reason'),
                $request->user(),
            );
        });

        return 'Entrada registrada.';
    }

    private function withdraw(StockMovementRequest $request): string
    {
        $this->ledger->withdraw(
            ProductBatch::findOrFail($request->validated('product_batch_id')),
            (string) $request->validated('quantity'),
            $request->validated('reason'),
            $request->user(),
        );

        return 'Saída registrada.';
    }

    private function adjust(StockMovementRequest $request): string
    {
        $movement = $this->ledger->adjustTo(
            ProductBatch::findOrFail($request->validated('product_batch_id')),
            (string) $request->validated('quantity'),
            (string) $request->validated('reason'),
            $request->user(),
        );

        return $movement === null ? 'A contagem confere com o saldo. Nada foi alterado.' : 'Ajuste registrado.';
    }

    /**
     * Lote informado na entrada: o existente com aquele código, ou um novo.
     * A validade de um lote existente não pode ser trocada por uma entrada.
     */
    private function batchForEntry(StockMovementRequest $request, Product $product): ProductBatch
    {
        $code = trim((string) $request->validated('batch_code'));
        $expiresOn = $request->validated('expires_on');

        $batch = $product->batches()->where('batch_code', $code)->lockForUpdate()->first();

        if ($batch === null) {
            return $product->batches()->create(['batch_code' => $code, 'expires_on' => $expiresOn]);
        }

        if ($expiresOn !== null && $batch->expires_on?->format('Y-m-d') !== $expiresOn) {
            throw ValidationException::withMessages([
                'expires_on' => 'O lote '.$code.' já existe com '.($batch->expires_on ? 'validade '.$batch->expires_on->format('d/m/Y') : 'validade não informada').'.',
            ]);
        }

        return $batch;
    }
}
