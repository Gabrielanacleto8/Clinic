<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Convenções que mantêm as telas utilizáveis no celular. O resultado visual é
 * conferido no navegador; aqui só se garante que uma view nova não as esqueça.
 */
class ResponsiveViewsTest extends TestCase
{
    public function test_listing_tables_turn_into_stacked_rows_on_narrow_screens(): void
    {
        $checked = 0;

        foreach (File::allFiles(resource_path('views')) as $file) {
            preg_match_all('/<table\b[^>]*>.*?<\/table>/s', $file->getContents(), $tables);

            foreach ($tables[0] as $table) {
                // O calendário do mês (table-fixed) e tabelas sem cabeçalho de colunas já cabem na tela.
                if (! str_contains($table, 'scope="col"') || str_contains($table, 'table-fixed')) {
                    continue;
                }

                $opening = strstr($table, '>', true);

                $this->assertStringContainsString('table-stack', $opening, "Tabela sem table-stack em {$file->getRelativePathname()}");
                $this->assertStringContainsString('data-label="', $table, "Tabela sem data-label nas células em {$file->getRelativePathname()}");
                $checked++;
            }
        }

        $this->assertGreaterThan(0, $checked);
    }

    public function test_horizontal_scroll_areas_keep_hidden_labels_inside(): void
    {
        foreach (File::allFiles(resource_path('views')) as $file) {
            $source = $file->getContents();

            // Sem "relative", um sr-only dentro da área rolável alarga a página inteira.
            $this->assertSame(
                substr_count($source, 'overflow-x-auto'),
                substr_count($source, 'relative overflow-x-auto'),
                "overflow-x-auto sem relative em {$file->getRelativePathname()}",
            );
        }
    }
}
