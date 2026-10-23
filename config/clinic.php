<?php

return [

    /*
    | Chave do índice cego (HMAC) usado para buscar e garantir unicidade de
    | dados criptografados, como o CPF. É independente da APP_KEY: trocá-la
    | invalida todos os índices já gravados.
    */
    'blind_index_key' => env('BLIND_INDEX_KEY'),

    /*
    | Datas são gravadas em UTC e convertidas para este fuso na exibição.
    */
    'timezone' => env('CLINIC_TIMEZONE', 'America/Sao_Paulo'),

    /*
    | Fotos de procedimento e documentos (PDF). As fotos são sempre recodificadas em JPEG no servidor, o
    | que descarta metadados (GPS, aparelho) e qualquer conteúdo embutido.
    */
    'stock' => [
        // Com quantos dias de antecedência um lote entra no alerta de vencimento.
        'expiry_alert_days' => (int) env('STOCK_EXPIRY_ALERT_DAYS', 60),
        // Tamanho máximo do XML de nota fiscal aceito na importação.
        'invoice_max_upload_kilobytes' => 1024,
    ],

    /*
    | Depois de quantos meses sem revisão a ficha de saúde passa a ser
    | destacada como desatualizada, na ficha e antes do registro do atendimento.
    */
    'anamnesis_review_months' => (int) env('CLINIC_ANAMNESIS_REVIEW_MONTHS', 12),

    'documents' => [
        'max_upload_kilobytes' => 10240,
    ],

    'photos' => [
        'max_upload_kilobytes' => 8192,
        'max_per_upload' => 5,
        'max_megapixels' => 50,
        'max_dimension' => 2560,
        'thumbnail_dimension' => 480,
        // Foto de identificação do paciente: quadrada, recortada no centro.
        'avatar_dimension' => 320,
    ],

];
