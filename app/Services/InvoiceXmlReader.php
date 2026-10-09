<?php

namespace App\Services;

use App\Enums\ProductCategory;
use App\Enums\ProductUnit;
use App\Exceptions\InvalidInvoiceException;
use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Str;

/**
 * Lê o XML de uma NF-e e devolve o que interessa ao estoque: fornecedor,
 * número e, de cada item, produto, quantidade, custo e lotes. O arquivo é
 * só lido: nada dele é gravado nem executado.
 */
class InvoiceXmlReader
{
    /**
     * Itens por nota. Acima disso o formulário de conferência passaria do
     * número de campos que o PHP aceita em um envio.
     */
    public const MAX_ITEMS = 60;

    private const UNITS = [
        'ML' => ProductUnit::Milliliter,
        'G' => ProductUnit::Gram, 'GR' => ProductUnit::Gram,
        'MG' => ProductUnit::Milligram,
        'FR' => ProductUnit::Vial, 'FRS' => ProductUnit::Vial, 'FRASCO' => ProductUnit::Vial, 'FA' => ProductUnit::Vial,
        'AMP' => ProductUnit::Ampoule, 'AMPOLA' => ProductUnit::Ampoule,
        'SER' => ProductUnit::Syringe, 'SERINGA' => ProductUnit::Syringe,
        'CX' => ProductUnit::Box, 'CAIXA' => ProductUnit::Box,
    ];

    /**
     * Palpite de categoria pelos quatro primeiros dígitos do NCM. O usuário confere na revisão.
     */
    private const CATEGORIES = [
        '3002' => ProductCategory::Injectable,   // toxinas e produtos imunológicos
        '3004' => ProductCategory::Injectable,   // medicamentos em doses
        '3304' => ProductCategory::Cosmetic,     // produtos de beleza e cuidados da pele
        '3005' => ProductCategory::Disposable,   // curativos e gazes
        '4015' => ProductCategory::Disposable,   // luvas
        '9018' => ProductCategory::Disposable,   // seringas, agulhas e instrumentos
    ];

    /**
     * @return array{number: string, series: string, issued_on: ?string, supplier: string, items: list<array<string, mixed>>}
     *
     * @throws InvalidInvoiceException
     */
    public function read(string $xml): array
    {
        $xpath = new DOMXPath($this->load($xml));
        $info = $xpath->query('//*[local-name()="infNFe"]')->item(0);

        if ($info === null) {
            throw new InvalidInvoiceException('O arquivo não é o XML de uma nota fiscal eletrônica (NF-e).');
        }

        $text = fn (string $path, ?DOMNode $context = null): string => trim((string) $xpath->evaluate('string('.$this->path($path).')', $context ?? $info));
        $details = $xpath->query($this->path('det'), $info);

        if ($details->length === 0) {
            throw new InvalidInvoiceException('A nota fiscal não tem itens.');
        }

        if ($details->length > self::MAX_ITEMS) {
            throw new InvalidInvoiceException('A nota tem '.$details->length.' itens. O limite por importação é de '.self::MAX_ITEMS.'.');
        }

        $items = [];

        foreach ($details as $detail) {
            $items[] = $this->item($xpath, $detail, $text);
        }

        return [
            'number' => Str::limit($text('ide/nNF'), 60, ''),
            'series' => $text('ide/serie'),
            'issued_on' => $this->date($text('ide/dhEmi') ?: $text('ide/dEmi')),
            'supplier' => Str::limit($text('emit/xNome'), 150, ''),
            'items' => $items,
        ];
    }

    /**
     * @throws InvalidInvoiceException
     */
    private function load(string $xml): DOMDocument
    {
        // Nota fiscal não tem DOCTYPE. Recusar qualquer um fecha a porta para
        // entidades externas e expansão de entidades (XXE).
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new InvalidInvoiceException('O arquivo não é um XML de nota fiscal válido.');
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            // Sem acesso à rede e sem substituição de entidades.
            $loaded = $xml !== '' && $document->loadXML($xml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        // A segunda checagem cobre arquivos em codificações que escondem o DOCTYPE da busca acima.
        if (! $loaded || $document->doctype !== null) {
            throw new InvalidInvoiceException('O arquivo não é um XML de nota fiscal válido.');
        }

        return $document;
    }

    /**
     * @param  callable(string, ?DOMNode): string  $text
     * @return array<string, mixed>
     */
    private function item(DOMXPath $xpath, DOMNode $detail, callable $text): array
    {
        $barcode = $text('prod/cEAN', $detail);
        $quantity = $this->quantity($text('prod/qCom', $detail));
        $lots = [];

        // Rastreabilidade (grupo "rastro"): um item pode vir dividido em vários lotes.
        foreach ($xpath->query($this->path('prod/rastro'), $detail) as $trace) {
            $lots[] = [
                'batch_code' => Str::limit($text('nLote', $trace), 60, ''),
                'quantity' => $this->quantity($text('qLote', $trace)),
                'manufactured_on' => $this->date($text('dFab', $trace)),
                'expires_on' => $this->date($text('dVal', $trace)),
            ];
        }

        return [
            'name' => Str::limit($text('prod/xProd', $detail), 150, ''),
            // Código de barras quando a nota traz um; senão, o código do fornecedor.
            'code' => Str::limit(preg_match('/^\d{8,14}$/', $barcode) ? $barcode : $text('prod/cProd', $detail), 40, ''),
            'invoice_unit' => Str::limit($text('prod/uCom', $detail), 10, ''),
            'unit' => (self::UNITS[mb_strtoupper($text('prod/uCom', $detail))] ?? ProductUnit::Unit)->value,
            'category' => (self::CATEGORIES[substr($text('prod/NCM', $detail), 0, 4)] ?? ProductCategory::Other)->value,
            'quantity' => $quantity,
            'unit_cost' => number_format((float) $text('prod/vUnCom', $detail), 2, '.', ''),
            'lots' => $lots !== [] ? $lots : [['batch_code' => '', 'quantity' => $quantity, 'manufactured_on' => null, 'expires_on' => null]],
        ];
    }

    /**
     * Caminho que ignora o namespace da NF-e: "emit/xNome" vira um XPath por nome local.
     */
    private function path(string $path): string
    {
        return implode('/', array_map(fn (string $name) => '*[local-name()="'.$name.'"]', explode('/', $path)));
    }

    private function quantity(string $value): string
    {
        return number_format((float) $value, 3, '.', '');
    }

    /**
     * Data (Y-m-d) no início do valor, como em "2026-10-01T10:00:00-03:00".
     */
    private function date(string $value): ?string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $parts) && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            ? $parts[0]
            : null;
    }
}
