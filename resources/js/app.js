// Sem scripts inline: a CSP só permite este arquivo. Todo comportamento é
// ligado por atributos data-*.

const onlyDigits = (value) => value.replace(/\D/g, '');

const masks = {
    cpf(digits) {
        const d = digits.slice(0, 11);
        let out = d.slice(0, 3);

        if (d.length > 3) out += `.${d.slice(3, 6)}`;
        if (d.length > 6) out += `.${d.slice(6, 9)}`;
        if (d.length > 9) out += `-${d.slice(9)}`;

        return out;
    },

    phone(digits) {
        const d = digits.slice(0, 11);

        if (d.length <= 2) return d;

        const rest = d.slice(2);
        const split = rest.length > 8 ? 5 : 4;

        return `(${d.slice(0, 2)}) ${rest.length > split ? `${rest.slice(0, split)}-${rest.slice(split)}` : rest}`;
    },

    cnpj(digits) {
        const d = digits.slice(0, 14);
        let out = d.slice(0, 2);

        if (d.length > 2) out += `.${d.slice(2, 5)}`;
        if (d.length > 5) out += `.${d.slice(5, 8)}`;
        if (d.length > 8) out += `/${d.slice(8, 12)}`;
        if (d.length > 12) out += `-${d.slice(12)}`;

        return out;
    },

    cep(digits) {
        const d = digits.slice(0, 8);

        return d.length > 5 ? `${d.slice(0, 5)}-${d.slice(5)}` : d;
    },
};

document.addEventListener('input', (event) => {
    const mask = masks[event.target.dataset?.mask];

    if (mask) {
        event.target.value = mask(onlyDigits(event.target.value));
    }
});

// <form data-confirm="..."> pede confirmação antes de enviar.
// Formulário POST já enviado não sai de novo enquanto a página não muda:
// duplo clique no registro de atendimento gravaria tudo duas vezes.
// Formulário que não troca de página (download) leva data-allow-resubmit.
document.addEventListener('submit', (event) => {
    const form = event.target;
    const message = form.dataset?.confirm;

    if (form.dataset.submitting !== undefined) {
        event.preventDefault();
        return;
    }

    if (message && !window.confirm(message)) {
        event.preventDefault();
        return;
    }

    if (!event.defaultPrevented && form.method === 'post' && form.dataset.allowResubmit === undefined) {
        form.dataset.submitting = '';

        // O botão mostra que o envio está em andamento; no 4G isso evita o segundo toque.
        const button = event.submitter;

        // Atributo próprio: data-label já é o rótulo das células de tabela (table-stack) e das permissões.
        if (button && button.dataset.submitLabel === undefined) {
            button.dataset.submitLabel = button.textContent;
            button.textContent = 'Enviando…';
            button.setAttribute('aria-disabled', 'true');
        }
    }
});

// Formulário longo (registro de atendimento, ficha de saúde, evolução) com
// data-keepalive: enquanto a pessoa digita, a sessão é renovada a cada poucos
// minutos, para o texto não se perder quando ela expira. Sem digitação, a
// sessão expira normalmente por inatividade.
const keepaliveForms = document.querySelectorAll('form[data-keepalive]');

if (keepaliveForms.length > 0) {
    const url = keepaliveForms[0].dataset.keepalive;
    const interval = 5 * 60 * 1000;
    let typedAt = 0;

    keepaliveForms.forEach((form) => form.addEventListener('input', () => (typedAt = Date.now())));

    const renew = async () => {
        try {
            const response = await fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });

            // Sessão perdida (celular bloqueado por muito tempo): avisa antes de a pessoa enviar e perder o texto.
            if (response.status === 401 || response.status === 419 || response.redirected) {
                keepaliveForms.forEach((form) => {
                    if (form.querySelector('[data-session-expired]')) return;

                    const notice = document.createElement('p');
                    notice.dataset.sessionExpired = '';
                    notice.setAttribute('role', 'alert');
                    notice.className = 'rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900';
                    notice.textContent = 'Sua sessão expirou. Copie o que digitou, entre de novo em outra aba e envie outra vez.';
                    form.prepend(notice);
                });
            }
        } catch {
            // Sem rede: nada a fazer agora; a próxima tentativa decide.
        }
    };

    window.setInterval(() => {
        if (Date.now() - typedAt < interval) renew();
    }, interval);

    // O celular suspende o temporizador com a tela bloqueada: ao voltar, renova na hora.
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && typedAt > 0) renew();
    });
}

// Página de impressão: o botão abre o diálogo de imprimir (ou salvar em PDF) do navegador.
document.querySelector('[data-print]')?.addEventListener('click', () => window.print());

// Abas [data-tabs] que rolam de lado no celular: a aba atual aparece inteira,
// centralizada quando possível, em vez de ficar cortada na borda.
document.querySelectorAll('[data-tabs]').forEach((tabs) => {
    const current = tabs.querySelector('[aria-current="page"]');

    if (current && tabs.scrollWidth > tabs.clientWidth) {
        tabs.scrollLeft = current.offsetLeft - (tabs.clientWidth - current.offsetWidth) / 2;
    }
});

// Voltar pelo navegador restaura a página do cache com o formulário ainda marcado.
window.addEventListener('pageshow', () => {
    document.querySelectorAll('form[data-submitting]').forEach((form) => delete form.dataset.submitting);
    document.querySelectorAll('[data-submit-label]').forEach((button) => {
        button.textContent = button.dataset.submitLabel;
        button.removeAttribute('aria-disabled');
        delete button.dataset.submitLabel;
    });
});

// Formulário que voltou com erro: o foco vai ao resumo dos erros (ou ao
// primeiro campo inválido), para a pessoa não procurar o que corrigir.
window.addEventListener('DOMContentLoaded', () => {
    const summary = document.querySelector('[data-form-errors]');
    const invalid = document.querySelector('[aria-invalid="true"]');
    const target = summary ?? invalid;

    if (target && !target.closest('dialog')) {
        target.focus({ preventScroll: false });
        target.scrollIntoView({ block: 'center' });
    }
});

// <select data-autosubmit>: trocar a opção já envia o formulário de filtro.
document.addEventListener('change', (event) => {
    if (event.target.matches('select[data-autosubmit]')) {
        event.target.form?.requestSubmit();
    }
});

// Menu lateral recolhível em telas pequenas. Em telas largas ele fica sempre
// visível por CSS e estes controles nem aparecem.
// Por id: a tag <html> também tem um atributo data-sidebar (estado recolhido).
const sidebar = document.getElementById('sidebar');

if (sidebar) {
    const backdrop = document.querySelector('[data-sidebar-backdrop]');
    const opener = document.querySelector('[data-sidebar-open]');
    const closer = sidebar.querySelector('[data-sidebar-close]');
    const isOpen = () => sidebar.dataset.open === 'true';

    const setOpen = (open, { restoreFocus = true } = {}) => {
        if (open === isOpen()) return;

        sidebar.dataset.open = String(open);
        backdrop.dataset.open = String(open);
        opener.setAttribute('aria-expanded', String(open));

        if (open) {
            closer.focus();
        } else if (restoreFocus) {
            opener.focus();
        }
    };

    opener.addEventListener('click', () => setOpen(true));
    closer.addEventListener('click', () => setOpen(false));
    backdrop.addEventListener('click', () => setOpen(false));

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') setOpen(false);

        // Com o menu aberto sobre a página, o Tab circula só dentro dele.
        if (event.key === 'Tab' && isOpen() && window.innerWidth < 1024) {
            const focusable = [...sidebar.querySelectorAll('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])')];
            const first = focusable[0];
            const last = focusable[focusable.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
    });

    // Ao alargar a janela o menu volta a ser fixo: descarta o estado "aberto".
    window.matchMedia('(min-width: 64rem)').addEventListener('change', () => setOpen(false, { restoreFocus: false }));
}

// Recolhe a barra lateral a uma faixa de ícones em telas largas. A escolha vai
// para um cookie, e o servidor já entrega a próxima página no estado certo,
// sem a barra "piscar" aberta antes de recolher.
const collapseButton = document.querySelector('[data-sidebar-collapse]');

if (collapseButton) {
    collapseButton.addEventListener('click', () => {
        const root = document.documentElement;
        const collapsed = root.dataset.sidebar !== 'collapsed';
        const label = collapsed ? 'Expandir menu' : 'Recolher menu';

        root.dataset.sidebar = collapsed ? 'collapsed' : 'expanded';
        collapseButton.setAttribute('aria-expanded', String(!collapsed));
        collapseButton.setAttribute('aria-label', label);
        collapseButton.title = label;

        const secure = location.protocol === 'https:' ? '; secure' : '';
        document.cookie = `sidebar=${root.dataset.sidebar}; path=/; max-age=31536000; samesite=lax${secure}`;
    });
}

// Botão de modo escuro no topo: troca na hora e guarda a escolha neste
// aparelho (o servidor lê o mesmo cookie para a próxima página já vir certa).
document.querySelector('[data-theme-toggle]')?.addEventListener('click', () => {
    const root = document.documentElement;
    const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    const dark = root.dataset.theme === 'dark' || (root.dataset.theme === 'system' && systemDark);

    root.dataset.theme = dark ? 'light' : 'dark';

    const secure = location.protocol === 'https:' ? '; secure' : '';
    document.cookie = `theme=${root.dataset.theme}; path=/; max-age=157680000; samesite=lax${secure}`;
});

// Tecla "/" fora de um campo leva à pesquisa do topo, como em outros sistemas.
const globalSearch = document.querySelector('[data-global-search]');

if (globalSearch) {
    document.addEventListener('keydown', (event) => {
        const typing = event.target.closest('input, textarea, select, [contenteditable="true"]');

        if (event.key === '/' && !typing && !event.ctrlKey && !event.metaKey && !event.altKey && globalSearch.offsetParent !== null) {
            event.preventDefault();
            globalSearch.focus();
        }
    });
}

// Fotos: antes do envio, reduz cada imagem no próprio
// navegador. O upload fica rápido e cabe nos limites do servidor. É só uma
// otimização: o servidor valida e recodifica tudo de novo, de qualquer forma.
// Vale para todo campo de foto da página (fotos do prontuário e foto de identificação).
document.querySelectorAll('[data-photo-input]').forEach((photoInput) => {
    const form = photoInput.closest('form');
    const status = form.querySelector('[data-photo-status]');
    const submit = form.querySelector('[data-photo-submit]');
    // Lista de miniaturas com "remover". Só nos formulários que a têm (o avatar não).
    const previews = form.querySelector('[data-photo-previews]');
    const maxFiles = Number(photoInput.dataset.maxFiles);
    // Fotos já escolhidas: no celular a câmera devolve uma por vez, então cada
    // escolha é somada às anteriores em vez de substituí-las.
    let selected = [];
    const maxDimension = Number(photoInput.dataset.maxDimension);
    const maxBytes = Number(photoInput.dataset.maxBytes);
    const maxRequestBytes = Number(photoInput.dataset.maxRequestBytes);
    let preparing = false;

    const megabytes = (bytes) => `${(bytes / 1048576).toFixed(1).replace('.', ',')} MB`;

    const encode = (bitmap, dimension, quality) => {
        const scale = Math.min(1, dimension / Math.max(bitmap.width, bitmap.height));
        const canvas = document.createElement('canvas');

        canvas.width = Math.max(1, Math.round(bitmap.width * scale));
        canvas.height = Math.max(1, Math.round(bitmap.height * scale));

        const context = canvas.getContext('2d');
        context.fillStyle = '#fff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);

        return new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
    };

    // Reduz até caber em `budget` bytes: primeiro baixa a qualidade, depois o tamanho.
    const shrink = async (file, budget) => {
        // imageOrientation aplica a rotação gravada pelo celular nos pixels.
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        let dimension = maxDimension;
        let quality = 0.85;
        let blob = null;

        try {
            for (let attempt = 0; attempt < 10; attempt++) {
                blob = await encode(bitmap, dimension, quality);

                if (!blob || blob.size <= budget) break;

                if (quality > 0.65) {
                    quality -= 0.1;
                } else {
                    dimension = Math.round(dimension * 0.8);
                }
            }
        } finally {
            bitmap.close();
        }

        // Mantém o original se ele já cabe e a versão reduzida não ficou menor.
        const keepOriginal = !blob || (file.size <= budget && blob.size >= file.size);

        return keepOriginal ? file : new File([blob], 'foto.jpg', { type: 'image/jpeg' });
    };

    const sync = () => {
        const transfer = new DataTransfer();

        selected.forEach((item) => transfer.items.add(item.file));
        photoInput.files = transfer.files;

        if (previews) {
            previews.replaceChildren(...selected.map((item, index) => {
                const entry = document.createElement('li');
                const image = document.createElement('img');
                const remove = document.createElement('button');

                entry.className = 'relative';
                image.src = item.url;
                image.alt = `Foto ${index + 1}`;
                image.className = 'aspect-square w-full rounded-lg border border-slate-200 object-cover';
                remove.type = 'button';
                remove.className = 'absolute top-1 right-1 rounded-full bg-white/90 px-2 py-0.5 text-xs font-medium text-red-700 shadow hover:bg-white';
                remove.textContent = 'Remover';
                remove.setAttribute('aria-label', `Remover a foto ${index + 1}`);
                remove.addEventListener('click', () => {
                    URL.revokeObjectURL(item.url);
                    selected.splice(index, 1);
                    sync();
                    announce([]);
                });
                entry.append(image, remove);

                return entry;
            }));
            previews.hidden = selected.length === 0;
        }
    };

    const announce = (unreadable) => {
        if (selected.length === 0) {
            status.textContent = '';
            return;
        }

        const total = selected.reduce((sum, item) => sum + item.file.size, 0);
        const plural = selected.length === 1 ? 'foto pronta' : 'fotos prontas';
        const warning = unreadable.length > 0
            ? ` Atenção: ${unreadable.join(', ')} não pôde ser lida pelo navegador e pode ser recusada; prefira JPEG ou PNG.`
            : '';

        status.textContent = `${selected.length} ${plural} para envio (${megabytes(total)}).${warning}`;
    };

    photoInput.addEventListener('change', async () => {
        const files = [...photoInput.files];

        if (files.length === 0) {
            sync();
            return;
        }

        // Um só arquivo (foto de identificação): a escolha nova substitui a anterior.
        if (maxFiles === 1) {
            selected.forEach((item) => URL.revokeObjectURL(item.url));
            selected = [];
        }

        if (selected.length + files.length > maxFiles) {
            sync();
            status.textContent = `Selecione no máximo ${maxFiles} fotos por envio. ${selected.length} já ${selected.length === 1 ? 'escolhida' : 'escolhidas'}.`;
            return;
        }

        preparing = true;
        submit.disabled = true;
        status.textContent = 'Preparando as fotos…';

        // Cada foto precisa caber no limite por arquivo e, somadas, no limite do
        // envio. A folga cobre os demais campos do formulário.
        const budget = Math.floor(Math.min(maxBytes, (maxRequestBytes * 0.9) / maxFiles) * 0.95);
        const unreadable = [];

        for (const file of files) {
            let prepared = file;

            try {
                prepared = await shrink(file, budget);
            } catch {
                // Arquivo que o navegador não decodifica (HEIC, por exemplo) segue
                // como está; o servidor decide, e a pessoa fica avisada.
                unreadable.push(file.name);
            }

            selected.push({ file: prepared, url: URL.createObjectURL(prepared) });
        }

        sync();
        announce(unreadable);
        submit.disabled = false;
        preparing = false;
    });

    form.addEventListener('submit', (event) => {
        if (preparing) {
            event.preventDefault();
            return;
        }

        submit.disabled = true;
        submit.textContent = 'Enviando…';
    });
});

// Seletor de paciente (agendamento e registro de atendimento): busca no servidor conforme a digitação.
// Os resultados entram na página só via textContent, nunca como HTML.
const picker = document.querySelector('[data-patient-picker]');

if (picker) {
    const input = picker.querySelector('[data-patient-search]');
    const hidden = picker.querySelector('[data-patient-id]');
    const list = picker.querySelector('[data-patient-options]');
    const status = picker.querySelector('[data-patient-status]');
    let results = [];
    let active = -1;
    let timer = null;
    let request = null;

    // Avisa o resto da página quando o paciente escolhido muda (ver os avisos de saúde, abaixo).
    const announce = () => picker.dispatchEvent(new CustomEvent('patientchange', { detail: { id: hidden.value } }));

    const close = () => {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        active = -1;
    };

    const highlight = (index) => {
        active = index;

        [...list.children].forEach((option, i) => option.setAttribute('aria-selected', String(i === index)));
        input.setAttribute('aria-activedescendant', list.children[index].id);
        list.children[index].scrollIntoView({ block: 'nearest' });
    };

    const choose = (patient) => {
        hidden.value = patient.id;
        input.value = patient.name;
        status.textContent = `Paciente selecionado: ${patient.name}, CPF ${patient.cpf}${patient.minor ? ', menor de idade' : ''}.`;
        close();
        announce();
    };

    const render = () => {
        list.replaceChildren(...results.map((patient, i) => {
            const option = document.createElement('li');
            const name = document.createElement('span');
            const details = document.createElement('span');

            option.id = `patient-option-${i}`;
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            option.className = 'cursor-pointer px-3 py-2 text-sm hover:bg-slate-50 aria-selected:bg-teal-50';
            name.className = 'block font-medium text-slate-900';
            name.textContent = patient.name;
            details.className = 'block text-xs text-slate-500';
            details.textContent = [
                `CPF ${patient.cpf}`,
                `nascimento ${patient.birth_date}${patient.minor ? ' (menor de idade)' : ''}`,
                patient.phone ? `tel. ${patient.phone}` : null,
            ].filter(Boolean).join(' · ');
            option.append(name, details);

            // mousedown (e não click) para escolher antes de o campo perder o foco.
            option.addEventListener('mousedown', (event) => {
                event.preventDefault();
                choose(patient);
            });

            return option;
        }));

        list.hidden = results.length === 0;
        input.setAttribute('aria-expanded', String(results.length > 0));
        input.removeAttribute('aria-activedescendant');
        active = -1;
    };

    const search = async (term) => {
        request?.abort();
        request = new AbortController();
        status.textContent = 'Buscando…';

        try {
            const response = await fetch(`${picker.dataset.url}?q=${encodeURIComponent(term)}`, {
                headers: { Accept: 'application/json' },
                signal: request.signal,
            });

            if (!response.ok) throw new Error(String(response.status));

            results = await response.json();
            render();

            // O servidor corta a lista: avisa em vez de parecer que são todos.
            const truncated = response.headers.get('X-Has-More') === '1';

            status.textContent = results.length === 0
                ? 'Nenhum paciente encontrado.'
                : truncated
                    ? `Mostrando os ${results.length} primeiros. Digite mais do nome para encontrar o paciente certo.`
                    : `${results.length} ${results.length === 1 ? 'paciente encontrado' : 'pacientes encontrados'}. Clique ou use as setas e Enter para escolher.`;
        } catch (error) {
            if (error.name === 'AbortError') return;

            results = [];
            render();
            status.textContent = 'Não foi possível buscar agora. Tente de novo.';
        }
    };

    input.addEventListener('input', () => {
        // Qualquer edição no texto desfaz a seleção anterior.
        if (hidden.value !== '') {
            hidden.value = '';
            announce();
        }

        clearTimeout(timer);

        const term = input.value.trim();

        if (term.length < 2) {
            request?.abort();
            results = [];
            render();
            status.textContent = 'Digite ao menos 2 letras para buscar.';

            return;
        }

        timer = setTimeout(() => search(term), 250);
    });

    input.addEventListener('keydown', (event) => {
        if (list.hidden || results.length === 0) return;

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            highlight((active + 1) % results.length);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            highlight((active - 1 + results.length) % results.length);
        } else if (event.key === 'Enter' && active >= 0) {
            event.preventDefault();
            choose(results[active]);
        } else if (event.key === 'Escape') {
            // Dentro de um diálogo, o Esc fecha só a lista, e não a janela junto.
            event.preventDefault();
            close();
        }
    });

    input.addEventListener('blur', close);
}

// Sessão de pacote no registro do atendimento: usada, o procedimento passa a
// ser o do pacote e a cobrança some (o pacote já foi cobrado). No diálogo, as
// sessões do paciente são buscadas quando ele é escolhido.
document.querySelectorAll('[data-plan-sessions]').forEach((block) => {
    const form = block.closest('form');
    const select = block.querySelector('[data-plan-session-select]');
    const charge = form.querySelector('[data-charge-fields]');
    const procedure = form.querySelector('select[name="procedure_id"]');

    const sync = () => {
        const option = select.selectedOptions[0];
        const usingSession = select.value !== '';

        charge.hidden = usingSession;
        charge.querySelectorAll('input, select').forEach((field) => (field.disabled = usingSession));

        if (usingSession && procedure && procedure.value !== option.dataset.procedure) {
            procedure.value = option.dataset.procedure;
            procedure.dispatchEvent(new Event('change', { bubbles: true }));
        }
    };

    select.addEventListener('change', sync);
    sync();

    const patientPicker = form.querySelector('[data-patient-picker]');

    if (!block.dataset.url || !patientPicker) return;

    const fill = (sessions) => {
        select.replaceChildren(select.options[0], ...sessions.map((session) => {
            const option = document.createElement('option');

            option.value = String(session.id);
            option.dataset.procedure = String(session.procedure_id);
            option.textContent = session.label;

            return option;
        }));

        block.hidden = sessions.length === 0;
        sync();
    };

    patientPicker.addEventListener('patientchange', async (event) => {
        select.value = '';
        fill([]);

        if (!event.detail.id) return;

        try {
            const response = await fetch(block.dataset.url.replace('__ID__', encodeURIComponent(event.detail.id)), { headers: { Accept: 'application/json' } });

            if (response.ok) fill((await response.json()).sessions);
        } catch {
            // Sem a lista, o atendimento é cobrado normalmente; a sessão pode ser usada num novo registro.
        }
    });
});

// Orçamento: escolher o procedimento preenche o valor do catálogo (se a linha
// ainda não tem valor) e o total é refeito a cada mudança. "Remover" limpa a
// linha, que volta a ser uma linha em branco (não gravada).
document.querySelectorAll('[data-plan-items]').forEach((list) => {
    const total = list.closest('form').querySelector('[data-plan-total]');
    const parse = (text) => Number(String(text).replace(/\./g, '').replace(',', '.')) || 0;
    const money = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });

    const discount = list.closest('form').querySelector('[data-plan-discount]');

    const refresh = () => {
        let sum = 0;
        let listSum = 0;

        list.querySelectorAll('[data-plan-item]').forEach((row) => {
            const procedure = row.querySelector('[data-plan-procedure]');

            if (procedure.value === '') return;

            const sessions = parse(row.querySelector('[data-plan-sessions-input]').value);
            const price = parse(row.querySelector('[data-plan-price]').value);

            sum += sessions * price;
            listSum += sessions * (procedure.selectedOptions[0]?.dataset.price ? parse(procedure.selectedOptions[0].dataset.price) : price);
        });

        total.textContent = money.format(sum);

        // Desconto sobre o preço de tabela: o servidor confere o limite de quem orça.
        if (discount) {
            const off = listSum - sum;

            discount.hidden = off < 0.005;
            discount.textContent = off < 0.005 ? '' : `Tabela ${money.format(listSum)} · desconto ${money.format(off)} (${(off / listSum * 100).toLocaleString('pt-BR', { maximumFractionDigits: 1 })}%)`;
        }
    };

    const fields = (row) => row.querySelectorAll('select, input');

    // O botão só aparece na linha com algo preenchido.
    const syncRemoveButton = (row) => {
        row.querySelector('[data-plan-remove]').hidden = [...fields(row)].every((field) => field.value === '');
    };

    list.addEventListener('click', (event) => {
        const button = event.target.closest('[data-plan-remove]');

        if (!button) return;

        const row = button.closest('[data-plan-item]');

        fields(row).forEach((field) => {
            field.value = '';
            field.classList.remove('input-error');
            field.removeAttribute('aria-invalid');
        });
        row.querySelectorAll('[data-plan-error]').forEach((error) => error.remove());
        syncRemoveButton(row);
        refresh();
        // O botão some: o foco vai para o procedimento da mesma linha.
        row.querySelector('[data-plan-procedure]').focus();
    });

    list.addEventListener('change', (event) => {
        const row = event.target.closest('[data-plan-item]');

        if (event.target.matches('[data-plan-procedure]') && row) {
            const price = row.querySelector('[data-plan-price]');
            const sessions = row.querySelector('[data-plan-sessions-input]');
            const option = event.target.selectedOptions[0];

            if (price.value === '' && option.dataset.price) price.value = option.dataset.price;
            if (sessions.value === '' && event.target.value !== '') sessions.value = '1';
        }

        if (row) syncRemoveButton(row);
        refresh();
    });

    list.addEventListener('input', (event) => {
        const row = event.target.closest('[data-plan-item]');

        if (row) syncRemoveButton(row);
        refresh();
    });
    refresh();
});

// Diálogo "Registrar atendimento": ao escolher o paciente, mostra os avisos de
// saúde dele (alergias, gestação), como o formulário do prontuário já faz.
const healthAlerts = document.querySelector('[data-health-alerts]');

if (picker && healthAlerts) {
    const items = healthAlerts.querySelector('[data-health-alerts-list]');
    let request = null;

    const show = (lines) => {
        items.replaceChildren(...lines.map((line) => {
            const item = document.createElement('li');

            item.className = 'whitespace-pre-line';
            item.textContent = line;

            return item;
        }));

        healthAlerts.hidden = lines.length === 0;
    };

    const load = async (patientId) => {
        request?.abort();
        show([]);

        if (!patientId) return;

        request = new AbortController();
        show(['Consultando os avisos de saúde…']);

        try {
            const response = await fetch(healthAlerts.dataset.url.replace('__ID__', encodeURIComponent(patientId)), {
                headers: { Accept: 'application/json' },
                signal: request.signal,
            });

            if (!response.ok) throw new Error(String(response.status));

            const health = await response.json();

            show([
                ...(health.missing ? ['Ficha de saúde não preenchida: confira alergias, medicamentos e condições de saúde antes do procedimento.'] : []),
                ...(health.stale_since ? [`Ficha de saúde sem revisão desde ${health.stale_since}: confira com o paciente se algo mudou.`] : []),
                ...health.alerts.map((alert) => (alert.label ? `${alert.label}: ${alert.text}` : alert.text)),
            ]);
        } catch (error) {
            if (error.name === 'AbortError') return;

            // Uma falha na consulta não pode parecer "paciente sem avisos".
            show(['Não foi possível consultar os avisos de saúde deste paciente. Confira a ficha de saúde antes de continuar.']);
        }
    };

    picker.addEventListener('patientchange', (event) => load(event.detail.id));
    load(picker.querySelector('[data-patient-id]').value);
}

// No agendamento, escolher o procedimento ajusta a duração para a estimada no catálogo.
document.addEventListener('change', (event) => {
    const select = event.target;
    const targetId = select.dataset?.durationSource;

    if (!targetId) return;

    const duration = select.selectedOptions[0]?.dataset.duration;
    const target = document.getElementById(targetId);

    // Duração já ajustada à mão não é sobrescrita (ver data-ends-at-duration).
    if (duration && target && target.dataset.touched === undefined && [...target.options].some((option) => option.value === duration)) {
        target.value = duration;
        target.dispatchEvent(new Event('change', { bubbles: true }));
    }
});

// Registro de atendimento: escolher o procedimento preenche o valor do
// catálogo e os produtos que ele normalmente usa. Só substitui o que está em
// branco ou ainda é a sugestão do procedimento anterior: o que o usuário
// digitou fica como está.
const procedureSelect = document.querySelector('[data-procedure-defaults]');

if (procedureSelect) {
    const defaults = JSON.parse(procedureSelect.dataset.procedureDefaults);
    const form = procedureSelect.form;
    const amount = form.querySelector('[data-treatment-amount]');
    const rows = [...form.querySelectorAll('[data-treatment-product]')].map((row) => ({
        batch: row.querySelector('[data-treatment-batch]'),
        unit: row.querySelector('[data-treatment-unit]'),
        unitField: row.querySelector('[data-treatment-unit-field]'),
        quantity: row.querySelector('[data-treatment-quantity]'),
        symbol: row.querySelector('[data-treatment-unit-symbol]'),
    }));
    const missing = form.querySelector('[data-treatment-missing]');
    const missingList = form.querySelector('[data-treatment-missing-list]');

    // Unidade física: só para lote de produto fracionado, e só as unidades
    // abertas daquele lote. Sem JavaScript, o servidor confere a escolha.
    // Com preferOpen, sugere a unidade aberta há mais tempo do lote (as opções
    // já vêm nessa ordem), para não abrir uma nova à toa.
    const syncUnits = (row, { preferOpen = false } = {}) => {
        const fractioned = row.batch.selectedOptions[0]?.hasAttribute('data-fractioned') ?? false;

        row.unitField.hidden = !fractioned;

        [...row.unit.options].forEach((option) => {
            if (option.value !== '') {
                option.hidden = option.dataset.batch !== row.batch.value;
                option.disabled = option.hidden;
            }
        });

        if (row.unit.selectedOptions[0]?.disabled || !fractioned) {
            row.unit.value = '';
        }

        if (fractioned && preferOpen) {
            row.unit.value = [...row.unit.options].find((option) => option.value !== '' && !option.disabled)?.value ?? '';
        }

        // A unidade de medida (U, mL, un) ao lado da quantidade: evita trocar U por mL.
        if (row.symbol) {
            row.symbol.textContent = row.batch.selectedOptions[0]?.dataset.unit ?? '';
        }
    };

    rows.forEach((row) => {
        syncUnits(row);
        row.batch.addEventListener('change', () => syncUnits(row, { preferOpen: true }));
    });

    const suggestionFor = (procedureId) => defaults[procedureId] ?? { amount: null, products: [], missing: [] };

    // As linhas preenchidas são exatamente os produtos sugeridos (ou nenhuma, se nada foi sugerido).
    const productsAre = (suggested) => {
        const filled = rows.filter((row) => row.batch.value !== '' || row.quantity.value !== '');

        return (
            filled.length === suggested.length &&
            filled.every(
                (row, index) =>
                    row.batch.value === String(suggested[index].product_batch_id) && row.quantity.value === suggested[index].quantity,
            )
        );
    };

    let previous = suggestionFor(procedureSelect.value);

    procedureSelect.addEventListener('change', () => {
        const next = suggestionFor(procedureSelect.value);

        if (amount.value === '' || amount.value === previous.amount) {
            amount.value = next.amount ?? '';
        }

        if (productsAre(previous.products) && next.products.length <= rows.length) {
            rows.forEach((row, index) => {
                row.batch.value = next.products[index]?.product_batch_id ?? '';
                row.quantity.value = next.products[index]?.quantity ?? '';
                syncUnits(row);
                row.unit.value = next.products[index]?.stock_unit_id ?? '';
            });
        }

        // Produtos típicos sem lote no estoque: o aviso diz quais ficaram de fora da sugestão.
        if (missing && missingList) {
            missingList.textContent = (next.missing ?? []).join(', ');
            missing.hidden = (next.missing ?? []).length === 0;
        }

        previous = next;
        showDiscount();
    });

    // Desconto sobre o preço de tabela (o valor do catálogo): "10%" ou "50,00"
    // recalcula o valor cobrado, e a nota mostra o desconto do valor digitado.
    // Só ajuda a digitar: o servidor confere o limite de quem registra.
    const discountInput = form.querySelector('[data-discount-input]');
    const discountNote = form.querySelector('[data-discount-note]');
    const limitText = discountNote?.textContent.trim() ?? '';
    const parseMoney = (text) => Number(String(text).trim().replace(/\./g, '').replace(',', '.'));
    const brl = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
    const listPrice = () => parseMoney(suggestionFor(procedureSelect.value).amount ?? '') || 0;

    const showDiscount = () => {
        if (!discountNote) return;

        const list = listPrice();
        const charged = parseMoney(amount.value);
        const off = list - charged;

        discountNote.textContent = list > 0 && charged > 0 && off >= 0.005
            ? `Tabela ${brl.format(list)} · desconto ${brl.format(off)} (${(off / list * 100).toLocaleString('pt-BR', { maximumFractionDigits: 1 })}%). ${limitText}`
            : limitText;
    };

    discountInput?.addEventListener('input', () => {
        const list = listPrice();
        const text = discountInput.value.trim();

        if (list <= 0 || text === '') return;

        const value = parseMoney(text.replace('%', ''));

        if (!Number.isFinite(value) || value < 0) return;

        const charged = text.endsWith('%') ? list * (1 - Math.min(value, 100) / 100) : Math.max(list - value, 0);

        amount.value = charged.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2, useGrouping: false });
        showDiscount();
    });

    amount.addEventListener('input', showDiscount);
    showDiscount();
}

// Listas com resumo em janela (atendimentos, orçamentos): clicar na linha (ou
// no link marcado com data-summary-link) abre o resumo na janela indicada em
// data-summary-dialog, buscado no servidor só nessa hora. Ctrl/Cmd+clique e o
// botão do meio continuam abrindo a página completa em outra aba. Botões,
// campos e outros links da linha seguem com a ação própria.
document.addEventListener('click', async (event) => {
    const row = event.target.closest('[data-summary]');

    if (!row || event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) return;
    if (event.target.closest('button, input, select, textarea, label')) return;

    const link = event.target.closest('a');

    if (link && !link.hasAttribute('data-summary-link')) return;

    const dialog = document.getElementById(row.dataset.summaryDialog);
    const body = dialog?.querySelector('[data-summary-body]');

    if (!body) return;

    event.preventDefault();

    const message = (text) => {
        const paragraph = document.createElement('p');
        paragraph.className = 'text-sm text-slate-600';
        paragraph.textContent = text;
        body.replaceChildren(paragraph);
    };

    // Só a última linha clicada preenche a janela, se as respostas chegarem fora de ordem.
    const url = row.dataset.summary;
    dialog.dataset.summaryCurrent = url;
    message('Carregando…');
    dialog.showModal();

    try {
        const response = await fetch(url, { headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' } });

        if (!response.ok) throw new Error(String(response.status));

        // HTML do próprio servidor, já escapado pelo Blade. O DOMParser não executa scripts.
        const page = new DOMParser().parseFromString(await response.text(), 'text/html');
        const content = page.querySelector('[data-summary-content]');

        if (dialog.dataset.summaryCurrent === url && content) {
            body.replaceChildren(document.importNode(content, true));
        }
    } catch {
        if (dialog.dataset.summaryCurrent === url) message(body.dataset.summaryError ?? 'Não foi possível carregar. Tente de novo ou abra a página completa.');
    }
});

// Janelas modais (<dialog>). O botão que abre pode preencher campos do
// formulário, como o lote da linha clicada: data-dialog-values='{"campo": valor}'.
// Com data-dialog-action ele também define para onde o formulário é enviado.
document.addEventListener('click', (event) => {
    const opener = event.target.closest('[data-dialog-open]');

    if (opener) {
        const dialog = document.getElementById(opener.dataset.dialogOpen);

        if (!dialog) return;

        for (const [name, value] of Object.entries(JSON.parse(opener.dataset.dialogValues ?? '{}'))) {
            const fields = [...dialog.querySelectorAll(`[name="${CSS.escape(name)}"]`)];
            const checkbox = fields.find((field) => field.type === 'checkbox');

            // Em um checkbox, o campo oculto de mesmo nome (o valor de "desmarcado") fica como está.
            if (checkbox) {
                checkbox.checked = Boolean(value);
            } else if (fields[0]) {
                fields[0].value = value ?? '';
            }
        }

        if (opener.dataset.dialogAction) {
            dialog.querySelector('form').action = opener.dataset.dialogAction;
        }

        // Texto de contexto do diálogo, como a parcela que está sendo paga.
        const summary = dialog.querySelector('[data-dialog-summary]');

        if (summary && opener.dataset.dialogSummary) {
            summary.textContent = opener.dataset.dialogSummary;
        }

        dialog.showModal();

        return;
    }

    // Fecha pelo botão de fechar ou por um clique fora da janela (no fundo escurecido).
    // Arrastar uma seleção de texto para fora da janela também termina num clique no
    // fundo: só fecha se o clique começou ali. Janelas de formulário longo (size="lg")
    // fecham só pelo X ou por "Cancelar".
    if (event.target.closest('[data-dialog-close]')) {
        event.target.closest('dialog')?.close();
    } else if (event.target instanceof HTMLDialogElement && backdropPressed === event.target && !event.target.classList.contains('max-w-3xl')) {
        event.target.close();
    }

    backdropPressed = null;
});

let backdropPressed = null;

document.addEventListener('mousedown', (event) => {
    backdropPressed = event.target instanceof HTMLDialogElement ? event.target : null;
});

// Formulário de um diálogo que voltou com erro: reabre para mostrar a mensagem.
document.querySelector('dialog[data-dialog-autoshow]')?.showModal();

// Cadastro de paciente: digitar o CEP preenche o endereço pelo servidor
// (a CSP não deixa o navegador consultar serviços externos). Só preenche o
// que está em branco, e uma falha não atrapalha: a pessoa digita à mão.
const cepInput = document.querySelector('[data-cep-lookup]');

if (cepInput) {
    const form = cepInput.form;
    const fields = { street: 'street', district: 'district', city: 'city', state: 'state' };
    let lastLookup = '';

    cepInput.addEventListener('input', async () => {
        const digits = onlyDigits(cepInput.value);

        if (digits.length !== 8 || digits === lastLookup) return;

        lastLookup = digits;

        try {
            const response = await fetch(cepInput.dataset.cepLookup.replace('__CEP__', digits), { headers: { Accept: 'application/json' } });

            if (!response.ok) return;

            const address = await response.json();

            Object.entries(fields).forEach(([key, name]) => {
                const field = form.elements[name];

                if (field && field.value === '' && address[key]) {
                    field.value = address[key];
                }
            });
        } catch {
            // Sem consulta, sem preenchimento: o formulário segue normal.
        }
    });
}

// Galeria de fotos: o botão "Comparar" só abre com exatamente duas fotos marcadas.
const compareForm = document.querySelector('[data-compare-form]');

if (compareForm) {
    const button = compareForm.querySelector('[data-compare-submit]');
    const picks = () => [...compareForm.querySelectorAll('[data-compare-pick]:checked')];

    const update = () => {
        const count = picks().length;

        button.disabled = count !== 2;
        button.textContent = count === 2 ? 'Comparar as 2 fotos' : `Comparar (${count}/2)`;
    };

    compareForm.addEventListener('change', (event) => {
        if (!event.target.matches('[data-compare-pick]')) return;

        // A terceira marcação desmarca a mais antiga.
        const chosen = picks();

        if (chosen.length > 2) {
            chosen.find((box) => box !== event.target).checked = false;
        }

        update();
    });

    update();
}

// Botão que abre um diálogo pode trocar a confirmação do formulário dele
// (data-dialog-confirm): receber uma parcela de cartão de crédito recebe todas,
// e a pergunta precisa dizer isso. Sem o atributo, a confirmação anterior sai.
document.addEventListener('click', (event) => {
    const opener = event.target.closest('[data-dialog-open]');
    const form = opener && document.getElementById(opener.dataset.dialogOpen)?.querySelector('form');

    if (!form || opener.dataset.dialogConfirm === undefined) return;

    if (opener.dataset.dialogConfirm) {
        form.dataset.confirm = opener.dataset.dialogConfirm;
    } else {
        delete form.dataset.confirm;
    }
});

// Tela de permissões: marcar uma permissão marca e trava as de que ela depende
// (data-requires, valores separados por vírgula), por perfil. O servidor aplica
// a mesma regra ao salvar; aqui o administrador só vê antes o que vai conceder.
const permissionBoxes = [...document.querySelectorAll('input[type="checkbox"][data-requires]')];

if (permissionBoxes.length > 0) {
    const byRole = new Map();

    permissionBoxes.forEach((box) => {
        const role = box.name;
        if (!byRole.has(role)) byRole.set(role, new Map());
        byRole.get(role).set(box.value, box);
    });

    const refresh = () => {
        byRole.forEach((boxes) => {
            const grantedBy = new Map();

            boxes.forEach((box) => {
                if (!box.checked || box.disabled) return;

                const pending = (box.dataset.requires || '').split(',').filter(Boolean);

                while (pending.length > 0) {
                    const value = pending.pop();
                    const required = boxes.get(value);

                    if (!required || grantedBy.has(value)) continue;

                    grantedBy.set(value, box.dataset.label || box.value);
                    pending.push(...(required.dataset.requires || '').split(',').filter(Boolean));
                }
            });

            boxes.forEach((box) => {
                const by = grantedBy.get(box.value);
                const note = box.parentElement.querySelector('[data-requires-note]');

                if (by) {
                    box.checked = true;
                    box.disabled = true;
                    if (note) note.textContent = `incluída por: ${by}`;
                } else {
                    box.disabled = false;
                    if (note) note.textContent = '';
                }
            });
        });
    };

    // Caixa travada não é enviada pelo navegador: na hora de enviar, ela é destravada.
    permissionBoxes[0].form?.addEventListener('submit', () => permissionBoxes.forEach((box) => (box.disabled = false)));
    document.addEventListener('change', (event) => {
        if (event.target.matches('input[type="checkbox"][data-requires]')) refresh();
    });
    refresh();
}

// Linhas repetidas de um formulário (produtos típicos do procedimento):
// "Adicionar" clona a última linha vazia até o limite, renumerando os campos.
document.querySelector('[data-rows-add]')?.addEventListener('click', (event) => {
    const button = event.currentTarget;
    const container = document.getElementById(button.dataset.rowsAdd);
    const rows = [...container.querySelectorAll('fieldset')];
    const max = Number(button.dataset.rowsMax || Infinity);

    if (rows.length >= max) {
        button.disabled = true;
        return;
    }

    const index = rows.length;
    const clone = rows[rows.length - 1].cloneNode(true);

    clone.querySelectorAll('[name], [id], label[for]').forEach((element) => {
        for (const attribute of ['name', 'id', 'for']) {
            const value = element.getAttribute(attribute);
            if (value) element.setAttribute(attribute, value.replace(/\d+/, String(index)));
        }
    });
    clone.querySelectorAll('input').forEach((input) => (input.value = ''));
    clone.querySelectorAll('select').forEach((select) => (select.value = ''));
    clone.querySelectorAll('.input-error').forEach((element) => element.classList.remove('input-error'));
    clone.querySelectorAll('p.text-red-700').forEach((element) => element.remove());
    clone.querySelectorAll('label').forEach((label) => label.classList.add('sr-only'));

    container.appendChild(clone);
    clone.querySelector('select, input')?.focus();

    if (rows.length + 1 >= max) button.disabled = true;
});

// Agendamento: "Termina às" acompanha data, hora e duração.
const endsAtSource = document.querySelector('[data-ends-at-source]');
const endsAtDuration = document.querySelector('[data-ends-at-duration]');

if (endsAtSource && endsAtDuration) {
    const hint = document.getElementById(`${endsAtDuration.id}-hint`);

    const update = () => {
        const start = new Date(endsAtSource.value);
        const minutes = Number(endsAtDuration.value);

        if (!hint) return;

        if (Number.isNaN(start.getTime()) || !minutes) {
            hint.textContent = 'Termina às —';
            return;
        }

        const end = new Date(start.getTime() + minutes * 60 * 1000);
        const sameDay = end.toDateString() === start.toDateString();

        hint.textContent = `Termina às ${end.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })}${sameDay ? '' : ' (dia seguinte)'}`;
    };

    endsAtSource.addEventListener('input', update);
    endsAtDuration.addEventListener('change', update);
    update();
}

// Agendamento: escolher o procedimento só ajusta a duração se a pessoa ainda
// não a tiver mexido (ver data-duration-source, acima).
document.querySelector('[data-ends-at-duration]')?.addEventListener('change', (event) => {
    if (event.isTrusted) event.target.dataset.touched = '';
});

// Agendamento: horários já ocupados do profissional no dia escolhido, para o
// encaixe não ser por tentativa. Só horários e procedimentos, sem paciente.
const occupancy = document.querySelector('[data-occupancy]');

if (occupancy) {
    const form = occupancy.closest('form');
    const dateInput = form.querySelector('[name="scheduled_at"]');
    const professionalSelect = form.querySelector('[name="professional_id"]');
    const status = occupancy.querySelector('[data-occupancy-status]');
    const list = occupancy.querySelector('[data-occupancy-list]');
    let controller = null;

    const load = async () => {
        const date = dateInput.value.slice(0, 10);
        const professional = professionalSelect.value;

        if (!date || !professional) {
            occupancy.hidden = true;
            return;
        }

        controller?.abort();
        controller = new AbortController();
        occupancy.hidden = false;
        status.textContent = 'Consultando…';
        list.replaceChildren();

        const params = new URLSearchParams({ data: date, profissional: professional });

        if (occupancy.dataset.except) params.set('exceto', occupancy.dataset.except);

        try {
            const response = await fetch(`${occupancy.dataset.url}?${params}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: controller.signal,
            });

            if (!response.ok) throw new Error(String(response.status));

            const { slots } = await response.json();

            status.textContent = slots.length === 0 ? 'Nenhum horário ocupado neste dia.' : '';

            slots.forEach((slot) => {
                const item = document.createElement('li');
                item.className = 'rounded-md bg-slate-100 px-2 py-1 tabular-nums';
                item.textContent = `${slot.from}–${slot.to}${slot.procedure ? ` · ${slot.procedure}` : ''}`;
                list.append(item);
            });
        } catch (error) {
            if (error.name !== 'AbortError') status.textContent = 'Não foi possível consultar os horários.';
        }
    };

    dateInput.addEventListener('change', load);
    professionalSelect.addEventListener('change', load);
    load();
}


// Formulário com data-warn-unsaved="mensagem" (inventário): sair da página com
// algum campo preenchido pede confirmação, porque o que foi digitado se perde.
// O envio do próprio formulário não pergunta.
document.querySelectorAll('form[data-warn-unsaved]').forEach((form) => {
    let dirty = false;

    form.addEventListener('input', () => (dirty = [...form.querySelectorAll('input:not([type=hidden])')].some((field) => field.value !== '')));
    form.addEventListener('submit', () => (dirty = false));

    window.addEventListener('beforeunload', (event) => {
        if (dirty) {
            event.preventDefault();
            event.returnValue = form.dataset.warnUnsaved;
        }
    });
});

// Diálogo cujo formulário leva data-dialog-reset volta aos valores iniciais a
// cada abertura: a quantidade digitada para um lote não fica para o próximo.
// Na captura, antes de o abridor preencher os campos (data-dialog-values).
document.addEventListener(
    'click',
    (event) => {
        const opener = event.target.closest('[data-dialog-open]');
        const form = opener && document.getElementById(opener.dataset.dialogOpen)?.querySelector('form[data-dialog-reset]');

        form?.reset();
        form?.querySelectorAll('[data-unit-preview]').forEach((preview) => (preview.textContent = ''));
    },
    true,
);

// Campo de unidades fechadas de produto fracionado (data-unit-content="100"
// data-unit-symbol="U"): mostra ao lado quanto isso dá na unidade de medida.
const unitPreview = (field) => {
    const output = field.closest('form, td')?.querySelector(`output[for="${CSS.escape(field.id)}"]`);
    const units = Number(String(field.value).replace(',', '.'));
    const content = Number(String(field.dataset.unitContent).replace(',', '.'));

    if (!output) return;

    output.textContent =
        field.value === '' || Number.isNaN(units) || Number.isNaN(content)
            ? ''
            : `= ${(units * content).toLocaleString('pt-BR', { maximumFractionDigits: 3 })} ${field.dataset.unitSymbol}`;
};

document.querySelectorAll('[data-unit-content]').forEach(unitPreview);
document.addEventListener('input', (event) => {
    if (event.target.dataset?.unitContent !== undefined) unitPreview(event.target);
});

// Conferência da nota fiscal: os campos de cadastro de produto novo só valem
// quando nenhum produto do estoque foi escolhido para o item.
document.querySelectorAll('[data-invoice-item]').forEach((item) => {
    const select = item.querySelector('[data-invoice-product]');
    const block = item.querySelector('[data-invoice-new-product]');
    const sync = () => (block.hidden = select.value !== '');

    sync();
    select.addEventListener('change', sync);
});
