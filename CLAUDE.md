# Clinic

Sistema web de gestão de uma clínica de estética biomédica. Trata dados pessoais, de saúde e fotos de pacientes, que são dados sensíveis pela LGPD.

## Stack

- PHP 8.3, Laravel 13, MySQL 8.4
- MySQL de desenvolvimento via `docker compose`, em `127.0.0.1:3307`
- Testes com PHPUnit contra MySQL, no banco `clinic_testing`

## Comandos

- `docker compose up -d --wait` sobe o banco
- `composer test` roda os testes
- `./vendor/bin/pint` formata o código
- `composer audit` verifica vulnerabilidades nas dependências
- `php artisan clinic:check` confere a configuração de produção (chaves, HTTPS, pasta das fotos)
- `php artisan clinic:reset-password EMAIL` define senha provisória pelo terminal; `php artisan clinic:prune` limpa cache e sessões expirados (agendado)
- Produção: `docker-compose.production.yml`, passo a passo em `docs/deploy.md`

## Domínio

O fluxo central é paciente → agenda → atendimento → procedimento → estoque → financeiro. Cada módulo referencia o anterior por chave estrangeira: não copie dado que já existe em outra tabela.

- Código em inglês, interface e URLs em português (`/pacientes`, `/agenda`, `/estoque`).
- Perfis em `App\Enums\UserRole`. Permissões em `App\Enums\Permission`, concedidas por perfil na tabela `role_permissions` e lidas uma vez por requisição por `App\Services\RolePermissions`. O administrador tem sempre acesso total. Policies checam `User::hasPermission()`.
- `role`, `is_active` e `password_changed_at` do usuário ficam fora do mass assignment de propósito.
- Dados sensíveis usam o cast `encrypted`: CPF e observações do paciente, ficha de saúde, textos clínicos do atendimento e evoluções. Busca e unicidade de CPF passam por `App\Support\BlindIndex`.
- Leitura e alteração de paciente, prontuário, agenda, financeiro e permissões são registradas com `App\Services\AuditLogger`. No estoque, o cadastro de produto e toda movimentação também. A trilha é consultada só pelo administrador em `/auditoria` (filtro por paciente para responder ao titular); toda ação nova ganha rótulo em `AuditAction::label()`.
- Datas são gravadas em UTC e convertidas com `App\Support\ClinicTime`. Números digitados no padrão brasileiro passam por `App\Support\LocalizedDecimal`.
- Rotas do prontuário são aninhadas em `/pacientes/{patient}` com `scopeBindings`, para um registro nunca ser alcançado pelo paciente errado. A ficha do paciente é só consulta: atendimento se registra pela agenda ou pela lista de atendimentos, nunca por ela. O prontuário para imprimir (`/pacientes/{patient}/prontuario`) também abre paciente arquivado, para quem pode ver arquivados. Autorização de uso de imagem em divulgação fica em `patients.image_use_consented_at`, gravada pelo controller.
- Agenda: `Appointment`. Toda gravação passa por `AppointmentScheduler`, que impede horários sobrepostos por profissional. Arquivar o paciente cancela os agendamentos em aberto dele. Reabrir só agendamento de hoje em diante e de profissional ativo; falta só a partir do dia do agendamento. "Atendido" só é definido ao registrar o atendimento. Qualquer agendamento em aberto, de hoje ou de dia anterior, pode receber o registro (`Appointment::isOpenForTreatment()`): confirmar presença é opcional.
- Atendimento: é só o registro do que foi feito, para controle. Não há fila, sala de espera, cronômetro nem rascunho. `Treatment` é gravado em um passo por `TreatmentRecorder`, que na mesma transação baixa o estoque, gera o financeiro (na data do atendimento) e encerra o agendamento; pelo diálogo, encerra o agendamento em aberto do paciente no dia do atendimento. Registrado é imutável, sem edição nem exclusão: informação nova entra em `TreatmentNote`. Registro feito com erro é anulado por `TreatmentVoider` (permissão `records.void`, com motivo criptografado): devolve o estoque, exclui a cobrança (recusa se já há pagamento), reabre o agendamento e marca `voided_at`; o registro continua no prontuário. Só quem tem `finance.manage` dá a cobrança por recebida no registro. O formulário sugere o valor e os produtos típicos do procedimento, mas só baixa do estoque o que o usuário enviar. Depois de registrar, o usuário volta ao módulo de onde partiu (`App\Enums\TreatmentOrigin`: Início, Agenda ou Atendimentos), nunca a uma URL vinda do formulário. Na lista de atendimentos, o registro abre em janela pelo resumo `/pacientes/{patient}/atendimentos/{treatment}/resumo`, buscado só ao clicar e auditado como leitura.
- Estoque: saldo só muda por `StockLedger`, que grava a movimentação e o saldo do lote juntos. Nunca atribua `product_batches.quantity` direto. Tipos em `StockMovementType`: entrada (com custo, fornecedor e nota fiscal na própria movimentação), saída, perda, ajuste, consumo, que só nasce do registro do atendimento, e estorno (`Reversal`), que só nasce de `StockLedger::reverseTreatment()` na anulação e devolve consumo e sobra ao mesmo lote e unidade. Saída, perda e ajuste exigem motivo; na entrada ele é opcional, porque custo, fornecedor e nota fiscal já a documentam. Lote vencido não recebe entrada e só sai como perda; ajuste e inventário exigem a permissão `stock.adjust`. Fabricação e validade de um lote se corrigem com `stock.adjust`, auditadas com as datas antigas e novas. No fracionado, saída, perda, ajuste e inventário valem só para o lacrado, em múltiplos de `unit_content`; a unidade de medida não muda depois que o produto tem lotes. Custos unitários têm 4 casas. Lotes são usados na ordem `ProductBatch::firstToExpire()`. Toda entrada passa por `StockReceiver`. Produto fracionado (`Product::isFractioned()`, com `unit_content`) é usado por unidade física (`StockUnit`), aberta só por `StockLedger::openUnit()`, no estoque ou no registro do atendimento; ela entra em unidades inteiras e saída, perda e ajuste do lote nunca alcançam o que está nas unidades abertas. Unidade fora do prazo depois de aberta (`hours_after_opening`), de lote vencido ou de produto inativo não vai para paciente e aparece nos alertas para descarte. Sem `multi_patient_use`, a sobra é descartada como perda no próprio atendimento. Toda baixa grava em `unit_cost` o custo médio do lote naquele momento, que dá o custo do consumo e o valor perdido. A importação de nota fiscal lê o XML da NF-e com `InvoiceXmlReader` (recusa DOCTYPE e entidades) e não guarda o arquivo; a nota fica registrada pela chave de acesso em `stock_invoices` (nunca importada duas vezes), a conferência fica na sessão sob token próprio (`PendingInvoiceImports`) e o custo vem do valor total do item ÷ quantidade lançada.
- Financeiro: lançamentos só nascem de `FinancialRecorder`. O valor total é a soma das parcelas, cada uma de pelo menos R$ 0,01 (`InstallmentPlan::fits()`), e pago é ter `paid_at`. Receita no cartão de crédito (`PaymentMethod::settlesAllInstallments()`) é recebida inteira pela maquininha: pagar ou desfazer uma parcela vale para todas as do lançamento. Despesa no cartão continua parcela a parcela.
- Anexos: `PatientAttachment` guarda fotos e documentos do paciente. Foto pode ser de um atendimento ou avulsa (retroativa, com `taken_on`), enviada pela ficha; documento é sempre de um atendimento. Todo envio de foto passa por `PhotoUploader`, que usa `PhotoProcessor` (recodifica em JPEG). Ver e remover foto é só por `/pacientes/{patient}/fotos/{photo}`, resolvida por `Patient::photos()`. Todo arquivo é gravado por `AttachmentVault` (criptografa no disco privado `patient_photos`). Nunca use o nome de arquivo enviado nem sirva anexo por URL pública.
- Foto de identificação (avatar): não é foto clínica nem `PatientAttachment`. Fica em `patients.avatar_path`, passa só por `PatientAvatar` (recorte quadrado de `PhotoProcessor::avatar()`, gravado pelo `AttachmentVault`) e sai só por `/pacientes/{patient}/avatar`. Segue a permissão do cadastro (ver e editar paciente), não a do prontuário. A foto trocada ou removida sai do disco.

## Convenções

- Rode os testes e o Pint antes de concluir qualquer alteração.
- Toda entrada externa é validada com Form Request. Nada de `$request->all()` direto em `create`/`update`.
- Autorização sempre por Policy ou Gate, nunca apenas escondendo elementos na interface.
- Consultas via Eloquent ou Query Builder com bindings. SQL cru só com parâmetros vinculados.
- Senhas validadas com `Password::defaults()`.
- Saída em Blade com `{{ }}`. `{!! !!}` exige justificativa e conteúdo sanitizado.
- Scripts e estilos inline precisam de `nonce="{{ Vite::cspNonce() }}"`, por causa da CSP.
- Segredos somente no `.env`. Nunca em código, testes, logs ou no `.env.example`.
- Dados de paciente não aparecem em logs nem em mensagens de erro. `QueryException` passa por `App\Support\SanitizedQueryReport`, sem valores; nada de `Log::` com dados de modelo de paciente.
- Em produção, debug, cookie seguro e sessão criptografada são forçados nos arquivos de config: não reintroduza `env()` sem essa trava. Variável que o sistema exige em produção entra em `App\Support\ProductionConfig`. Migrations não rodam no entrypoint: a ordem é backup → `migrate --force` → reinício.
- Páginas de erro ficam em `resources/views/errors`, sem `<style>` e sem acesso a banco ou sessão.
- Formulário POST que não troca de página (download) leva `data-allow-resubmit`: os demais são bloqueados contra envio duplo pelo `app.js`. Formulário longo leva `data-keepalive` para a sessão não expirar enquanto se digita.
- Toda funcionalidade nova entra com teste de feature, incluindo os casos de acesso negado.
- Na auditoria entram nomes de campos e identificadores, nunca valores de dados pessoais. O tipo do registro auditado é o apelido do morph map em `AppServiceProvider`: modelo novo entra lá antes de ser auditado.
- Sem JavaScript inline nem atributos `onclick`: comportamento vai em `resources/js/app.js`, ligado por atributos `data-*`.
- Pacientes são arquivados com soft delete, nunca apagados.
- Antes de criar tabela ou coluna, confirme que o dado não deriva do que já existe. Valor derivado só é gravado com motivo, explicado em comentário na migration.
- Conte as consultas de cada tela: use eager loading, carregue só o que a tela exibe e cubra listagens com teste de número de consultas.
- Janelas modais usam o componente `x-dialog`. Campos dentro delas recebem `scope` com o id do diálogo.
- Telas valem para celular e desktop. Tabela de listagem leva a classe `table-stack` e `data-label` em cada célula de valor, para virar blocos em tela estreita. Contêiner com `overflow-x-auto` leva também `relative`.

## Fluxo obrigatório de entrega

Toda implementação nova (funcionalidade, correção ou refatoração que toque `app/`, `routes/`, `database/` ou `resources/`) passa por dois agentes antes de ser dada como concluída, nesta ordem:

1. **Implementar** a alteração e rodar o Pint.
2. **Segurança**: chamar o subagente `security-reviewer` com o escopo da alteração. Ele devolve `VEREDITO: APROVADO` ou `REPROVADO` com achados. Com reprovação, corrija os achados e chame de novo até aprovar. Não siga para o QA com achado CRÍTICO ou ALTO em aberto.
3. **QA**: só depois da aprovação de segurança, chamar o subagente `qa-tester` com o mesmo escopo. Ele escreve os testes que faltam, roda `composer test` e devolve `QA: APROVADO` ou `REPROVADO`. Defeito de produção apontado por ele volta para o passo 1 e repete o ciclo inteiro, incluindo a segurança.

Só relate a tarefa como concluída com os dois vereditos aprovados, e inclua no relatório final o resumo de cada um. Os agentes ficam em `.claude/agents/`.
