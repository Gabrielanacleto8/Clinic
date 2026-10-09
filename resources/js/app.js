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
document.addEventListener('submit', (event) => {
    const message = event.target.dataset?.confirm;

    if (message && !window.confirm(message)) {
        event.preventDefault();
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

// Fotos de procedimento: antes do envio, reduz cada imagem no próprio
// navegador. O upload fica rápido e cabe nos limites do servidor. É só uma
// otimização: o servidor valida e recodifica tudo de novo, de qualquer forma.
const photoInput = document.querySelector('[data-photo-input]');

if (photoInput) {
    const form = photoInput.closest('form');
    const status = form.querySelector('[data-photo-status]');
    const submit = form.querySelector('[data-photo-submit]');
    const maxFiles = Number(photoInput.dataset.maxFiles);
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

    photoInput.addEventListener('change', async () => {
        const files = [...photoInput.files];

        if (files.length === 0) {
            status.textContent = '';
            return;
        }

        if (files.length > maxFiles) {
            photoInput.value = '';
            status.textContent = `Selecione no máximo ${maxFiles} fotos por envio.`;
            return;
        }

        preparing = true;
        submit.disabled = true;
        status.textContent = 'Preparando as fotos…';

        // Cada foto precisa caber no limite por arquivo e, somadas, no limite do
        // envio. A folga cobre os demais campos do formulário.
        const budget = Math.floor(Math.min(maxBytes, (maxRequestBytes * 0.9) / files.length) * 0.95);
        const transfer = new DataTransfer();

        for (const file of files) {
            try {
                transfer.items.add(await shrink(file, budget));
            } catch {
                // Arquivo que o navegador não decodifica segue como está; o servidor decide.
                transfer.items.add(file);
            }
        }

        photoInput.files = transfer.files;

        const total = [...photoInput.files].reduce((sum, file) => sum + file.size, 0);
        const plural = files.length === 1 ? 'foto pronta' : 'fotos prontas';

        status.textContent = `${files.length} ${plural} para envio (${megabytes(total)}).`;
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
}

// Seletor de paciente (agendamento e novo atendimento): busca no servidor conforme a digitação.
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
        status.textContent = `Paciente selecionado: ${patient.name}, CPF ${patient.cpf}.`;
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
            details.textContent = `CPF ${patient.cpf} · nascimento ${patient.birth_date}`;
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

        try {
            const response = await fetch(`${picker.dataset.url}?q=${encodeURIComponent(term)}`, {
                headers: { Accept: 'application/json' },
                signal: request.signal,
            });

            if (!response.ok) throw new Error(String(response.status));

            results = await response.json();
            render();

            status.textContent = results.length === 0
                ? 'Nenhum paciente encontrado.'
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

// Novo atendimento pelo Início: ao escolher o paciente, mostra os avisos de
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

        try {
            const response = await fetch(healthAlerts.dataset.url.replace('__ID__', encodeURIComponent(patientId)), {
                headers: { Accept: 'application/json' },
                signal: request.signal,
            });

            if (!response.ok) throw new Error(String(response.status));

            const alerts = await response.json();

            show([
                ...(alerts.pregnant_or_lactating ? ['Gestante ou lactante.'] : []),
                ...(alerts.allergies ? [`Alergias: ${alerts.allergies}`] : []),
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

    if (duration && target && [...target.options].some((option) => option.value === duration)) {
        target.value = duration;
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
    if (event.target.closest('[data-dialog-close]') || event.target instanceof HTMLDialogElement) {
        event.target.closest('dialog')?.close();
    }
});

// Formulário de um diálogo que voltou com erro: reabre para mostrar a mensagem.
document.querySelector('dialog[data-dialog-autoshow]')?.showModal();
