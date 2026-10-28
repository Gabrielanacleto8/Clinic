<?php

namespace App\Support;

use App\Models\ClinicProfile;
use App\Models\Patient;
use App\Models\Procedure;

/**
 * Termo de consentimento de um procedimento, com as marcações trocadas pelos
 * dados do paciente e da clínica. O resultado é texto puro: a página o
 * exibe escapado, preservando só as quebras de linha.
 */
final class ConsentTerm
{
    /**
     * Marcações aceitas no texto do procedimento => o que significam (mostrado no formulário).
     */
    public const TOKENS = [
        '{paciente}' => 'nome do paciente',
        '{cpf}' => 'CPF do paciente',
        '{nascimento}' => 'data de nascimento',
        '{procedimento}' => 'nome do procedimento',
        '{clinica}' => 'razão social da clínica',
        '{data}' => 'data de hoje',
    ];

    /**
     * Modelo usado quando o procedimento não tem texto próprio. É um ponto de
     * partida: cada clínica deve revisá-lo com a assessoria jurídica dela.
     */
    public const DEFAULT_TEMPLATE = <<<'TXT'
Eu, {paciente}, CPF {cpf}, nascido(a) em {nascimento}, declaro que fui informado(a) de forma clara sobre o procedimento {procedimento}: sua finalidade, como é realizado, os resultados esperados, que não são garantidos, os cuidados antes e depois, as alternativas existentes e os possíveis riscos e efeitos adversos, como vermelhidão, inchaço, hematomas, dor, assimetria e reações alérgicas.

Declaro que informei com verdade meu histórico de saúde, alergias, medicamentos em uso, gestação ou amamentação e procedimentos anteriores, e que pude tirar todas as minhas dúvidas.

Comprometo-me a seguir as orientações recebidas e a comunicar à clínica qualquer reação ou intercorrência.

Autorizo, de forma livre e esclarecida, a realização do procedimento por {clinica}, sabendo que posso revogar esta autorização a qualquer momento antes de sua realização.
TXT;

    public static function render(Procedure $procedure, Patient $patient, ClinicProfile $clinic): string
    {
        $template = filled($procedure->consent_template) ? (string) $procedure->consent_template : self::DEFAULT_TEMPLATE;

        return strtr($template, [
            '{paciente}' => $patient->name,
            '{cpf}' => $patient->formattedCpf(),
            '{nascimento}' => $patient->birth_date->format('d/m/Y'),
            '{procedimento}' => $procedure->name,
            '{clinica}' => $clinic->displayName(),
            '{data}' => ClinicTime::format(now(), 'd/m/Y'),
        ]);
    }
}
