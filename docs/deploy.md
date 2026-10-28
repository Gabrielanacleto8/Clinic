# Colocar o Clinic em produção

Guia para instalar o Clinic num servidor próprio (VPS) com Docker. O sistema guarda dados de saúde e fotos de pacientes, que são dados sensíveis pela LGPD: não pule a parte de chaves e backup.

O que sobe com o `docker-compose.production.yml`:

| Serviço | O que faz |
| --- | --- |
| `web` | nginx: recebe o usuário nas portas 80 e 443, redireciona tudo para HTTPS e serve os arquivos de `public/` |
| `app` | PHP-FPM com o sistema. Não tem porta publicada |
| `scheduler` | Tarefas diárias do Laravel (limpeza de cache e sessões expirados) |
| `mysql` | MySQL 8.4. Não tem porta publicada |
| `backup` | Cópia diária do banco e das fotos, com retenção |

## 1. Servidor

- Linux com Docker e o plugin Docker Compose.
- Um domínio apontando para o IP do servidor (ex.: `sistema.suaclinica.com.br`).
- Portas 80 e 443 liberadas no firewall. Nenhuma outra precisa ficar aberta para a internet, além do SSH.
- Relógio do servidor sincronizado (NTP). Datas são gravadas em UTC e mostradas no fuso da clínica.

## 2. Código e variáveis

```sh
git clone <repositório> /opt/clinic
cd /opt/clinic
cp .env.example .env
chmod 600 .env
```

Preencha no `.env`:

| Variável | Valor |
| --- | --- |
| `APP_ENV` | `production` |
| `APP_URL` | `https://sistema.suaclinica.com.br` (com https) |
| `APP_KEY` | gere com `docker run --rm php:8.3-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"` |
| `BLIND_INDEX_KEY` | gere com `openssl rand -hex 32` |
| `CLINIC_DOMAIN` | `sistema.suaclinica.com.br` (sem https), usado pelo nginx |
| `DB_DATABASE`, `DB_USERNAME` | ex.: `clinic` e `clinic` |
| `DB_PASSWORD`, `DB_ROOT_PASSWORD` | duas senhas fortes diferentes: `openssl rand -hex 24` |
| `LOG_LEVEL` | `warning` |
| `BACKUP_PATH` | pasta do servidor para as cópias, ex.: `/srv/clinic-backups` |
| `BACKUP_PASSPHRASE` | obrigatória: `openssl rand -hex 32`. Sem ela o backup não roda (só com `BACKUP_ALLOW_PLAINTEXT=1`, para quem cifra o destino por outro meio) |
| `TRUSTED_PROXIES` | em branco (ver "Proxy na frente" abaixo) |

`APP_DEBUG`, `SESSION_SECURE_COOKIE` e `SESSION_ENCRYPT` não importam em produção: o sistema força debug desligado, cookie seguro e sessão criptografada.

### Guarda das chaves

**Antes de cadastrar o primeiro paciente**, copie `APP_KEY`, `BLIND_INDEX_KEY` e `BACKUP_PASSPHRASE` para um cofre de senhas fora do servidor (gerenciador de senhas da clínica, com acesso de pelo menos duas pessoas).

- Sem a `APP_KEY`, CPFs, ficha de saúde, textos clínicos, fotos e documentos ficam ilegíveis para sempre, inclusive no backup.
- Sem a `BLIND_INDEX_KEY`, a busca e a unicidade do CPF param de funcionar.
- Sem a `BACKUP_PASSPHRASE`, as cópias criptografadas não abrem.

**Nunca troque a `APP_KEY` sem manter a antiga.** Se precisar trocar (vazamento, saída de alguém com acesso), coloque a chave antiga em `APP_PREVIOUS_KEYS` e a nova em `APP_KEY`. O que já estava criptografado continua legível com a antiga; o que for gravado depois usa a nova. Não remova a antiga de `APP_PREVIOUS_KEYS` enquanto existirem dados gravados com ela. A `BLIND_INDEX_KEY` não pode ser trocada.

## 3. Certificado HTTPS (Let's Encrypt)

Na primeira vez, com as portas 80 e 443 ainda livres:

```sh
sudo apt install certbot
sudo certbot certonly --standalone -d sistema.suaclinica.com.br
```

O certificado fica em `/etc/letsencrypt`, que o serviço `web` lê. Para a renovação automática funcionar com o nginx no ar, troque o modo para webroot:

```sh
mkdir -p /opt/clinic/certbot-www
sudo certbot certonly --webroot -w /opt/clinic/certbot-www -d sistema.suaclinica.com.br \
  --deploy-hook "docker compose -f /opt/clinic/docker-compose.production.yml exec web nginx -s reload"
```

O certbot instalado pelo apt já agenda a renovação.

## 4. Primeira instalação

```sh
cd /opt/clinic
docker compose -f docker-compose.production.yml build
docker compose -f docker-compose.production.yml up -d mysql
docker compose -f docker-compose.production.yml run --rm app php artisan migrate --force
docker compose -f docker-compose.production.yml up -d
docker compose -f docker-compose.production.yml exec app php artisan clinic:create-admin
```

O `clinic:create-admin` pede nome, e-mail e senha no terminal (a senha não aparece). As permissões padrão dos perfis já nascem nas migrations; não há seeder em produção. **Nunca rode `php artisan db:seed` em produção**: ele cria pacientes fictícios.

Ao subir, o `app` roda `php artisan clinic:check` e se recusa a iniciar se faltar `APP_KEY`, `BLIND_INDEX_KEY`, se `APP_URL` não for https ou se a pasta das fotos não aceitar gravação. O motivo aparece em `docker compose -f docker-compose.production.yml logs app`.

Confira:

- `https://sistema.suaclinica.com.br/up` responde 200.
- `http://sistema.suaclinica.com.br` redireciona para https.
- Entrar com o administrador, cadastrar os usuários da equipe e revisar a aba **Permissões**.

## 5. Atualizar para uma nova versão

Sempre nesta ordem: **backup → migrations → reinício**.

```sh
cd /opt/clinic
git pull
docker compose -f docker-compose.production.yml exec backup /opt/clinic-backup/backup.sh
docker compose -f docker-compose.production.yml build
docker compose -f docker-compose.production.yml run --rm app php artisan migrate --force
docker compose -f docker-compose.production.yml up -d
```

O backup antes das migrations é obrigatório: o MySQL confirma cada alteração de tabela na hora, então uma migration que falha no meio deixa o banco pela metade, e a volta é restaurar a cópia. Os caches de configuração, rotas e views são refeitos sozinhos quando o `app` reinicia.

Para avisar os usuários durante a atualização: `docker compose -f docker-compose.production.yml exec app php artisan down` antes e `php artisan up` depois.

## 6. Backup

O serviço `backup` roda todo dia às `BACKUP_HOUR` (padrão 2h, no fuso `CLINIC_TIMEZONE`) e grava em `BACKUP_PATH` uma pasta `clinic-AAAAMMDD-HHMMSS` com:

- `database.sql.gz` — o banco inteiro (`mysqldump --single-transaction`, sem travar o sistema);
- `files.tar.gz` — fotos e documentos, que já estão criptografados com a `APP_KEY`, e o logo da clínica com os ícones do aplicativo (pasta `clinica/`, sem criptografia: é a marca pública);
- `SHA256SUMS` — para conferir que a cópia não foi corrompida.

Os dois arquivos saem como `.enc` (AES-256 com a `BACKUP_PASSPHRASE`). A cifra não autentica o conteúdo e o `SHA256SUMS` fica na mesma pasta: guarde as cópias onde só a clínica grava, para ninguém trocar um arquivo e refazer as somas. Cópias com mais de `BACKUP_RETENTION_DAYS` dias são apagadas.

Para fazer uma cópia agora: `docker compose -f docker-compose.production.yml exec backup /opt/clinic-backup/backup.sh`.
Para ver se o backup está rodando: `docker compose -f docker-compose.production.yml logs backup`.

**A cópia no mesmo servidor não protege contra perda do servidor.** Leve `BACKUP_PATH` para fora todo dia (outro servidor, armazenamento em nuvem com acesso restrito, disco externo), por exemplo com `rsync` ou `rclone` agendado no cron do servidor. Use `BACKUP_PASSPHRASE` sempre que a cópia sair do servidor: o banco tem nome, telefone e e-mail dos pacientes em texto puro.

Sem Docker, o equivalente é: `mysqldump --single-transaction` do banco, cópia da pasta `PATIENT_PHOTOS_PATH` e as duas chaves guardadas no cofre.

### Teste de restauração

Uma cópia só vale se abrir. Teste ao instalar e depois a cada três meses, num banco separado, sem tocar no de produção:

```sh
cd /opt/clinic
docker compose -f docker-compose.production.yml exec mysql sh -c \
  'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -e "CREATE DATABASE clinic_restore_test; GRANT ALL ON clinic_restore_test.* TO \"$MYSQL_USER\"@\"%\";"'

docker compose -f docker-compose.production.yml exec \
  -e DB_DATABASE=clinic_restore_test -e FILES_DIR=/tmp/restore-test \
  backup /opt/clinic-backup/restore.sh /backups/clinic-AAAAMMDD-HHMMSS --sim

docker compose -f docker-compose.production.yml exec mysql sh -c \
  'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -e "SELECT COUNT(*) FROM clinic_restore_test.patients; DROP DATABASE clinic_restore_test;"'
```

O número de pacientes deve bater com o do sistema. Anote a data do teste.

### Restaurar de verdade

1. `docker compose -f docker-compose.production.yml stop app scheduler web`.
2. Restaure no banco de produção e na pasta real das fotos. No serviço `backup` a pasta das fotos é somente leitura, por isso a restauração roda num container temporário com o volume montado para gravação:

   ```sh
   docker compose -f docker-compose.production.yml run --rm \
     --entrypoint /opt/clinic-backup/restore.sh \
     -v clinic_patient-files:/restore-files -e FILES_DIR=/restore-files \
     backup /backups/clinic-AAAAMMDD-HHMMSS --sim
   ```
3. Confirme que o `.env` tem a **mesma** `APP_KEY` e `BLIND_INDEX_KEY` da época da cópia.
4. `docker compose -f docker-compose.production.yml up -d`.

## 7. Proxy na frente (opcional)

Com o nginx do `web` recebendo o usuário direto, deixe `TRUSTED_PROXIES` em branco: ele mesmo termina o HTTPS e passa ao PHP o IP real.

Se houver outro proxy na frente (Cloudflare, balanceador, outro nginx que termina o HTTPS), preencha `TRUSTED_PROXIES` com o IP ou a faixa dele. Sem isso, o sistema enxerga todos os usuários com o IP do proxy: uma pessoa errando a senha bloqueia a clínica inteira, a auditoria grava o IP errado e o HSTS não é enviado. Nunca use `*` se a aplicação puder ser alcançada sem passar pelo proxy.

## 8. Operação

| Situação | O que fazer |
| --- | --- |
| Usuário esqueceu a senha | O administrador define uma senha provisória em **Usuários**; ela também libera o bloqueio por tentativas |
| O administrador esqueceu a senha | `docker compose -f docker-compose.production.yml exec app php artisan clinic:reset-password email@clinica.com.br` |
| Conferir a configuração | `docker compose -f docker-compose.production.yml exec app php artisan clinic:check` |
| Ver erros | `docker compose -f docker-compose.production.yml exec app ls storage/logs` (um arquivo por dia, guardados por `LOG_DAILY_DAYS` dias). Erros de banco aparecem sem os valores da consulta |
| Pessoa saiu da equipe | Desative o usuário em **Usuários**: a sessão dele cai na próxima requisição |

## 9. LGPD: o que fica com a clínica

O sistema registra na auditoria quem viu ou alterou paciente, prontuário, agenda, financeiro e permissões, com IP e data, e criptografa os dados de saúde, fotos e documentos. Ficam com a clínica, fora do sistema:

- política de retenção do prontuário (o sistema arquiva, não apaga, pacientes);
- termos de consentimento e de uso de imagem assinados (podem ser anexados como PDF no atendimento);
- quem tem acesso ao servidor, ao `.env` e ao cofre das chaves;
- guarda e teste periódico das cópias fora do servidor.

## Riscos aceitos

- **Bloqueio de conta por e-mail.** Depois de 10 senhas erradas em 15 minutos, de qualquer lugar, a conta fica bloqueada por 15 minutos. Isso protege contra tentativas espalhadas por muitos endereços, mas quem souber o e-mail de alguém pode mantê-lo fora repetindo as tentativas. O administrador libera outro usuário definindo uma senha provisória; para liberar a própria conta, use `php artisan clinic:reset-password EMAIL` no servidor. Se isso acontecer com frequência, restrinja o acesso ao sistema (VPN ou lista de IPs no nginx).
- **Backup sem autenticação.** Ver "Backup": a cifra não detecta adulteração. Guarde as cópias em local onde só a clínica grava.

