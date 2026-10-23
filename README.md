# Clinic

Sistema web de gestão de uma clínica de estética biomédica: pacientes, agenda, registro de atendimentos, estoque e financeiro.

Para colocar em produção, siga [docs/deploy.md](docs/deploy.md).

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

Comandos de operação:

```sh
php artisan clinic:create-admin            # primeiro administrador (senha digitada de forma oculta)
php artisan clinic:reset-password EMAIL    # senha provisória para quem esqueceu, inclusive o administrador
php artisan clinic:check                   # confere chaves, HTTPS e a pasta das fotos antes de abrir o sistema
php artisan clinic:prune                   # apaga cache e sessões expirados (agendado todo dia às 4h)
```

Os testes rodam contra MySQL, no banco `clinic_testing`, criado automaticamente pelo container na primeira inicialização.

## Módulos

O fluxo principal é: paciente, agenda, atendimento, procedimento, estoque e financeiro. Cada etapa reutiliza a anterior por relacionamento, sem repetir informação.

- **Início:** indicadores do dia e do mês, agenda de hoje, próximos agendamentos e alertas de estoque. Cada bloco só aparece para quem tem a permissão correspondente.
- **Agenda:** calendário do mês ou da semana, com a lista do dia escolhido e filtro por profissional. Agendar, remarcar, confirmar presença, marcar falta, cancelar e reabrir. O sistema recusa horários sobrepostos para o mesmo profissional.
- **Pacientes:** cadastro com foto de identificação e ficha do paciente, só para consulta, com ficha de saúde e abas de atendimentos, produtos utilizados, fotos, evoluções e financeiro. Pela aba de fotos entram fotos retroativas, com a data em que foram feitas, para o acervo anterior ao sistema.
- **Procedimentos:** catálogo com valor, duração, categoria e os produtos normalmente utilizados.
- **Estoque:** produtos, lotes com validade, entradas (inclusive pela importação do XML da nota fiscal), saídas, perdas, ajustes, inventário, histórico de movimentações e alertas de estoque mínimo e de vencimento. Produto fracionado (frasco, seringa, ampola) é controlado por unidade física aberta, com prazo depois de aberto e descarte da sobra quando o fabricante não permite uso em outro paciente.
- **Financeiro:** receitas e despesas, parcelas, pagamentos e totais do mês.
- **Usuários e permissões:** três perfis, com o acesso de Profissional e Recepção configurável pelo administrador.
- **Auditoria:** o administrador consulta quem fez o quê, quando e de onde, com filtro por usuário, ação, período e paciente (o "Ver acessos" da ficha).

## Atendimento

O atendimento é o registro do que foi feito, para controle. Não há fila, sala de espera, cronômetro nem rascunho: ele é registrado em um passo, pela agenda (a partir do agendamento) ou pelo botão "Registrar atendimento" do Início e da lista de atendimentos.

Ao escolher o procedimento, o formulário sugere o valor do catálogo e os produtos que ele normalmente usa, no lote que vence primeiro. O usuário confere e registra. Em uma única transação o sistema grava o registro, baixa do estoque o que foi informado, gera o lançamento financeiro na data do atendimento e marca o agendamento como atendido. Se um lote estiver vencido ou sem saldo, nada é gravado.

O atendimento registrado é permanente: não é editado nem excluído, nem pelo administrador. Informação nova entra como **evolução**, que também não pode ser editada depois de salva. Fotos e documentos em PDF podem ser acrescentados depois.

Registro feito com erro (paciente, lote, quantidade ou valor errados) é **anulado**, com motivo: o estoque baixado volta, a cobrança é excluída e o agendamento volta para a agenda, para o registro certo ser feito. O registro anulado continua no prontuário, marcado. Se a cobrança já tem pagamento registrado, desfaça o pagamento no financeiro antes. Por padrão só o administrador anula (permissão "Anular atendimento registrado com erro").

Pelo botão "Registrar atendimento", se o paciente tem agendamento em aberto no dia do atendimento, ele passa para "Atendido". Só quem pode registrar pagamentos no financeiro marca a cobrança como já recebida; para os demais ela entra pendente.

O prontuário completo, ou um atendimento, sai em página própria para imprimir ou salvar em PDF ("Imprimir prontuário" na ficha), inclusive de paciente arquivado.

## Perfis de acesso

O administrador tem sempre acesso total, e só ele gerencia usuários e permissões. O acesso dos outros dois perfis é definido na aba **Permissões** do módulo de usuários. O padrão de instalação é:

| Permissão | Profissional | Recepção |
| --- | --- | --- |
| Ver, cadastrar e editar pacientes | Sim | Sim |
| Arquivar e restaurar pacientes | Não | Não |
| Ver o prontuário (ficha de saúde, atendimentos, fotos e documentos) | Sim | Não |
| Registrar atendimentos e preencher a ficha de saúde | Sim | Não |
| Anular atendimento registrado com erro | Não | Não |
| Ver e gerenciar a agenda (gerenciar inclui buscar pacientes) | Sim | Sim |
| Ver o catálogo de procedimentos | Sim | Não |
| Cadastrar e editar procedimentos | Não | Não |
| Ver o estoque | Sim | Não |
| Cadastrar produtos e movimentar o estoque | Não | Não |
| Fazer inventário e ajustar saldos (`stock.adjust`) | Não | Não |
| Ver e lançar no financeiro | Não | Não |

Além das permissões, vale uma regra fixa: só o administrador registra atendimento em nome de outro profissional.

Não há cadastro público nem recuperação de senha por e-mail. O administrador cria as contas com senha provisória, e o usuário é obrigado a trocá-la no primeiro acesso. Quem esqueceu a senha pede uma nova ao administrador; se for o próprio administrador, use `php artisan clinic:reset-password` no servidor.

## Fotos e documentos

- Ficam em `storage/app/private/patient-photos`, fora da pasta pública, e são **criptografadas com a `APP_KEY`**. Use `PATIENT_PHOTOS_PATH` para apontar outro volume.
- Só saem pelo sistema, depois da checagem de permissão. Abrir uma foto em tamanho real fica registrado na auditoria.
- Documentos são aceitos apenas em PDF, com a assinatura do arquivo conferida, e são sempre entregues como download.
- Toda foto é recodificada em JPEG no servidor. Isso remove localização e dados do aparelho e descarta qualquer conteúdo embutido no arquivo.
- O navegador reduz as fotos antes de enviar, respeitando os limites do PHP. Com os valores padrão (`upload_max_filesize=2M`, `post_max_size=8M`) já funciona. A imagem de produção usa `10M` e `48M`.
- **O backup precisa de quatro coisas juntas:** o banco, a pasta das fotos, a `APP_KEY` e a `BLIND_INDEX_KEY`. Sem a `APP_KEY`, as fotos e os textos clínicos ficam ilegíveis. O serviço de backup de produção copia banco e fotos todo dia; as chaves ficam fora do servidor (ver [docs/deploy.md](docs/deploy.md)).

## Segurança

- Segredos ficam apenas no `.env`, que não é versionado. O `.env.example` nunca recebe valores reais.
- `App\Http\Middleware\SecurityHeaders` aplica CSP com nonce, `X-Frame-Options`, `nosniff`, `Referrer-Policy` e HSTS sob HTTPS em todas as respostas.
- Scripts e estilos inline precisam do nonce: use `nonce="{{ Vite::cspNonce() }}"`.
- A política de senha padrão está em `AppServiceProvider`. Use sempre `Password::defaults()` nas validações.
- Em produção (`APP_ENV=production`) o debug fica sempre desligado, o cookie de sessão é sempre seguro e a sessão sempre criptografada, qualquer que seja o `.env`. O sistema só funciona por HTTPS e se recusa a abrir sem `APP_KEY` e `BLIND_INDEX_KEY` (`php artisan clinic:check` mostra o que falta).
- Login com bloqueio após 5 tentativas erradas por e-mail e IP, bloqueio de 15 minutos do e-mail depois de 10 falhas seguidas de qualquer origem, e teto de 20 tentativas por minuto por IP.
- Erros de banco vão para o log sem os valores da consulta (só o SQL com `?`, os códigos e o ponto do código). Os logs giram por dia (`LOG_DAILY_DAYS`).
- Páginas de erro próprias em português. Formulário enviado com a sessão expirada volta à tela com o que foi digitado (se o "Lembrar de mim" manteve o usuário conectado) ou leva ao login com a explicação.
- "Lembrar de mim" é opcional, vem desmarcado e vale por 30 dias (`AUTH_REMEMBER_DAYS`). Sair, trocar a senha ou desativar a conta invalida o acesso lembrado.
- CPF, observações, ficha de saúde e textos clínicos dos atendimentos são criptografados no banco. A busca e a unicidade do CPF usam um índice cego (HMAC) com a `BLIND_INDEX_KEY`.
- **Guarde `APP_KEY` e `BLIND_INDEX_KEY` em local seguro e com backup.** Perder a `APP_KEY` torna os CPFs ilegíveis. Trocar a `BLIND_INDEX_KEY` quebra a busca por CPF.
- Pacientes, atendimentos em andamento e anexos são arquivados, nunca apagados. Movimentações de estoque, evoluções e a trilha de auditoria só recebem inserções.
- A tabela `audit_logs` registra logins, alterações de usuários e todo acesso ou alteração de paciente, ficha de saúde, atendimento e foto, com usuário, IP e data. Só nomes de campos são gravados, nunca os valores.
- Atrás de proxy reverso ou balanceador que termina o HTTPS, preencha `TRUSTED_PROXIES` (ver `config/trustedproxy.php`). Sem isso, o limite de tentativas por IP e o IP da auditoria ficam incorretos e o HSTS não é enviado. Com o nginx do `docker-compose.production.yml` recebendo o usuário direto, deixe em branco.
