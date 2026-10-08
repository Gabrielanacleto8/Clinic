# Clinic

Sistema web de gestão de clínica.

## Stack

- PHP 8.3 e Laravel 13
- MySQL 8.4 (via Docker Compose em desenvolvimento)
- Vite para os assets

## Requisitos

- PHP 8.3+ com as extensões `pdo_mysql`, `mbstring`, `openssl`, `intl`, `sodium`, `gd` e `exif`
- Composer 2
- Node 22+
- Docker com Docker Compose

## Configuração local

```sh
cp .env.example .env
# Preencha DB_PASSWORD e DB_ROOT_PASSWORD no .env com valores fortes, por exemplo:
#   openssl rand -hex 24

composer install
php artisan key:generate
docker compose up -d --wait
php artisan migrate
npm install
npm run build

# Cria o primeiro administrador. Rode no seu terminal: a senha é digitada de forma oculta.
php artisan clinic:create-admin
```

Preencha também `BLIND_INDEX_KEY` no `.env` com `openssl rand -hex 32`.

Para ter dados fictícios em desenvolvimento, rode `php artisan db:seed`. Ele cria pacientes, o catálogo de procedimentos, produtos com estoque e uma agenda de exemplo. Cada bloco só roda se a tabela estiver vazia, então repetir o comando não duplica nada. O seeder não cria usuários, para nunca existir conta com senha padrão.

O MySQL do projeto sobe em `127.0.0.1:3307`, para não colidir com um MySQL já instalado na porta 3306.

## Dia a dia

```sh
composer dev          # servidor, fila, logs e Vite juntos
composer test         # suíte de testes (usa o banco clinic_testing)
./vendor/bin/pint     # formatação de código
composer audit        # vulnerabilidades conhecidas nas dependências
```

Os testes rodam contra MySQL, no banco `clinic_testing`, criado automaticamente pelo container na primeira inicialização.

## Módulos

O fluxo principal é: paciente, agenda, atendimento, procedimento, estoque e financeiro. Cada etapa reutiliza a anterior por relacionamento, sem repetir informação.

- **Início:** indicadores do dia e do mês, agenda de hoje, próximos agendamentos e alertas de estoque. Cada bloco só aparece para quem tem a permissão correspondente.
- **Agenda:** visões de dia, semana e mês. Agendar, remarcar, confirmar presença, marcar falta, cancelar e reabrir. O sistema recusa horários sobrepostos para o mesmo profissional.
- **Pacientes:** cadastro e página do paciente com ficha de saúde e abas de atendimentos, produtos utilizados, fotos, evoluções e financeiro.
- **Procedimentos:** catálogo com valor, duração, categoria e os produtos normalmente utilizados.
- **Estoque:** produtos, lotes com validade, entradas, saídas, ajustes, histórico de movimentações e alertas de estoque mínimo e de vencimento.
- **Financeiro:** receitas e despesas, parcelas, pagamentos e totais do mês.
- **Usuários e permissões:** três perfis, com o acesso de Profissional e Recepção configurável pelo administrador.

## Atendimento

O atendimento é o centro do sistema e tem duas situações.

- **Em andamento:** pode ser editado pelo profissional responsável ou pelo administrador. Não mexe em estoque nem em financeiro. Se nenhum produto for informado, o sistema sugere os produtos padrão do procedimento, escolhendo o lote que vence primeiro.
- **Finalizado:** na finalização informa-se o valor e a forma de pagamento. Em uma única transação o sistema baixa os produtos do estoque por lote, gera o lançamento financeiro e marca o agendamento de origem como atendido. Se um lote estiver vencido ou sem saldo, nada é gravado.

Um atendimento finalizado não pode mais ser alterado nem descartado, nem pelo administrador. Informações novas entram como **evolução**, que também não pode ser editada depois de salva. Fotos e documentos em PDF podem ser acrescentados.

## Perfis de acesso

O administrador tem sempre acesso total, e só ele gerencia usuários e permissões. O acesso dos outros dois perfis é definido na tela **Permissões**. O padrão de instalação é:

| Permissão | Profissional | Recepção |
| --- | --- | --- |
| Ver, cadastrar e editar pacientes | Sim | Sim |
| Arquivar e restaurar pacientes | Não | Não |
| Ver o prontuário (ficha de saúde, atendimentos, fotos e documentos) | Sim | Não |
| Registrar atendimentos e preencher a ficha de saúde | Sim | Não |
| Ver e gerenciar a agenda | Sim | Sim |
| Ver o catálogo de procedimentos | Sim | Não |
| Cadastrar e editar procedimentos | Não | Não |
| Ver o estoque | Sim | Não |
| Cadastrar produtos e movimentar o estoque | Não | Não |
| Ver e lançar no financeiro | Não | Não |

Além das permissões, valem duas regras fixas: só o profissional responsável ou o administrador edita e finaliza um atendimento em andamento, e o profissional comum não registra atendimento em nome de outro.

Não há cadastro público nem recuperação de senha por e-mail. O administrador cria as contas com senha provisória, e o usuário é obrigado a trocá-la no primeiro acesso.

## Fotos e documentos

- Ficam em `storage/app/private/patient-photos`, fora da pasta pública, e são **criptografadas com a `APP_KEY`**. Use `PATIENT_PHOTOS_PATH` para apontar outro volume.
- Só saem pelo sistema, depois da checagem de permissão. Abrir uma foto em tamanho real fica registrado na auditoria.
- Documentos são aceitos apenas em PDF, com a assinatura do arquivo conferida, e são sempre entregues como download.
- Toda foto é recodificada em JPEG no servidor. Isso remove localização e dados do aparelho e descarta qualquer conteúdo embutido no arquivo.
- O navegador reduz as fotos antes de enviar, respeitando os limites do PHP. Com os valores padrão (`upload_max_filesize=2M`, `post_max_size=8M`) já funciona. Em produção, `10M` e `40M` dão mais folga.
- **O backup precisa incluir três coisas juntas:** o banco, a pasta das fotos e a `APP_KEY`. Sem a chave, as fotos e os textos clínicos ficam ilegíveis.

## Segurança

- Segredos ficam apenas no `.env`, que não é versionado. O `.env.example` nunca recebe valores reais.
- `App\Http\Middleware\SecurityHeaders` aplica CSP com nonce, `X-Frame-Options`, `nosniff`, `Referrer-Policy` e HSTS sob HTTPS em todas as respostas.
- Scripts e estilos inline precisam do nonce: use `nonce="{{ Vite::cspNonce() }}"`.
- A política de senha padrão está em `AppServiceProvider`. Use sempre `Password::defaults()` nas validações.
- Em produção: `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true` e HTTPS obrigatório.
- Login com bloqueio após 5 tentativas erradas por e-mail e IP, e teto de 20 tentativas por minuto por IP.
- "Lembrar de mim" é opcional, vem desmarcado e vale por 30 dias (`AUTH_REMEMBER_DAYS`). Sair, trocar a senha ou desativar a conta invalida o acesso lembrado.
- CPF, observações, ficha de saúde e textos clínicos dos atendimentos são criptografados no banco. A busca e a unicidade do CPF usam um índice cego (HMAC) com a `BLIND_INDEX_KEY`.
- **Guarde `APP_KEY` e `BLIND_INDEX_KEY` em local seguro e com backup.** Perder a `APP_KEY` torna os CPFs ilegíveis. Trocar a `BLIND_INDEX_KEY` quebra a busca por CPF.
- Pacientes, atendimentos em andamento e anexos são arquivados, nunca apagados. Movimentações de estoque, evoluções e a trilha de auditoria só recebem inserções.
- A tabela `audit_logs` registra logins, alterações de usuários e todo acesso ou alteração de paciente, ficha de saúde, atendimento e foto, com usuário, IP e data. Só nomes de campos são gravados, nunca os valores.
- Atrás de proxy reverso ou balanceador, configure os proxies confiáveis do Laravel. Sem isso, o limite de tentativas por IP e o IP da auditoria ficam incorretos.
