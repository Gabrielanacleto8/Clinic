<?php

return [

    /*
    | Proxies reversos confiáveis (balanceador, Cloudflare, nginx externo que
    | termina o HTTPS). Só deles o sistema aceita os cabeçalhos X-Forwarded-*,
    | que definem o IP do usuário (auditoria e limite de tentativas de login) e
    | se a conexão é HTTPS (cookie seguro e HSTS).
    |
    | Em branco, nenhum proxy é confiável: é o certo quando o nginx que
    | recebe o usuário passa a requisição direto ao PHP (docker-compose de
    | produção). Lista de IPs ou faixas separadas por vírgula, por exemplo
    | "10.0.0.0/8,172.16.0.0/12". "*" confia em qualquer origem e só pode ser
    | usado quando a aplicação não é alcançável sem passar pelo proxy.
    */
    'proxies' => env('TRUSTED_PROXIES') ?: null,

];
