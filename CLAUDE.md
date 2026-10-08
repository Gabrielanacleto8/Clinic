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

## Domínio

O fluxo central é paciente → agenda → atendimento → procedimento → estoque → financeiro. Cada módulo referencia o anterior por chave estrangeira: não copie dado que já existe em outra tabela.

- Código em inglês, interface e URLs em português (`/pacientes`, `/agenda`, `/estoque`).
- Perfis em `App\Enums\UserRole`. Permissões em `App\Enums\Permission`, concedidas por perfil na tabela `role_permissions` e lidas uma vez por requisição por `App\Services\RolePermissions`. O administrador tem sempre acesso total. Policies checam `User::hasPermission()`.
- `role`, `is_active` e `password_changed_at` do usuário ficam fora do mass assignment de propósito.
- Dados sensíveis usam o cast `encrypted`: CPF e observações do paciente, ficha de saúde, textos clínicos do atendimento e evoluções. Busca e unicidade de CPF passam por `App\Support\BlindIndex`.
- Leitura e alteração de paciente, prontuário, agenda, financeiro e permissões são registradas com `App\Services\AuditLogger`.
- Datas são gravadas em UTC e convertidas com `App\Support\ClinicTime`. Números digitados no padrão brasileiro passam por `App\Support\LocalizedDecimal`.
- Rotas do prontuário são aninhadas em `/pacientes/{patient}` com `scopeBindings`, para um registro nunca ser alcançado pelo paciente errado.
- Agenda: `Appointment`. Toda gravação passa por `AppointmentScheduler`, que impede horários sobrepostos por profissional. "Atendido" só é definido ao finalizar o atendimento.
- Atendimento: `Treatment` nasce rascunho e é finalizado por `TreatmentFinalizer`, que baixa estoque, gera o financeiro e encerra o agendamento em uma transação. Finalizado é imutável: informação nova entra em `TreatmentNote`.
- Estoque: saldo só muda por `StockLedger`, que grava a movimentação e o saldo do lote juntos. Nunca atribua `product_batches.quantity` direto.
- Financeiro: lançamentos só nascem de `FinancialRecorder`. O valor total é a soma das parcelas, e pago é ter `paid_at`.
- Anexos: `TreatmentAttachment` guarda fotos e documentos. Fotos passam por `PhotoProcessor` (recodifica em JPEG). Todo arquivo é gravado por `AttachmentVault` (criptografa no disco privado `patient_photos`). Nunca use o nome de arquivo enviado nem sirva anexo por URL pública.

## Convenções

- Rode os testes e o Pint antes de concluir qualquer alteração.
- Toda entrada externa é validada com Form Request. Nada de `$request->all()` direto em `create`/`update`.
- Autorização sempre por Policy ou Gate, nunca apenas escondendo elementos na interface.
- Consultas via Eloquent ou Query Builder com bindings. SQL cru só com parâmetros vinculados.
- Senhas validadas com `Password::defaults()`.
- Saída em Blade com `{{ }}`. `{!! !!}` exige justificativa e conteúdo sanitizado.
- Scripts e estilos inline precisam de `nonce="{{ Vite::cspNonce() }}"`, por causa da CSP.
- Segredos somente no `.env`. Nunca em código, testes, logs ou no `.env.example`.
- Dados de paciente não aparecem em logs nem em mensagens de erro.
- Toda funcionalidade nova entra com teste de feature, incluindo os casos de acesso negado.
- Na auditoria entram nomes de campos e identificadores, nunca valores de dados pessoais.
- Sem JavaScript inline nem atributos `onclick`: comportamento vai em `resources/js/app.js`, ligado por atributos `data-*`.
- Pacientes são arquivados com soft delete, nunca apagados.
- Antes de criar tabela ou coluna, confirme que o dado não deriva do que já existe. Valor derivado só é gravado com motivo, explicado em comentário na migration.
- Conte as consultas de cada tela: use eager loading, carregue só o que a tela exibe e cubra listagens com teste de número de consultas.
- Janelas modais usam o componente `x-dialog`. Campos dentro delas recebem `scope` com o id do diálogo.
