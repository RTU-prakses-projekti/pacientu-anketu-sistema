const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content;

document.querySelectorAll('[data-confirm]').forEach((form) => form.addEventListener('submit', (event) => {
    if (!window.confirm(form.dataset.confirm)) event.preventDefault();
}));

document.querySelectorAll('[data-copy-target]').forEach((button) => button.addEventListener('click', async () => {
    const input = document.getElementById(button.dataset.copyTarget);
    if (!input) return;
    try { await navigator.clipboard.writeText(input.value); }
    catch { input.select(); document.execCommand('copy'); }
    button.textContent = button.dataset.copiedLabel || '✓';
}));

document.querySelectorAll('[data-range-control]').forEach((control) => {
    const input = control.querySelector('[data-range-input]');
    const output = control.querySelector('[data-range-output]');
    if (!input || !output) return;
    const syncValue = () => { output.value = input.value; output.textContent = input.value; };
    input.addEventListener('input', syncValue);
    syncValue();
});

document.querySelectorAll('[data-component-form]').forEach((form) => {
    const registry = JSON.parse(form.dataset.registry || '{}');
    const type = form.querySelector('[data-component-type]');
    const refresh = () => {
        const definition = registry[type.value] || {settings: []};
        form.querySelectorAll('[data-setting]').forEach((field) => field.hidden = !definition.settings.includes(field.dataset.setting));
        form.querySelector('[data-options]').hidden = !['single_choice', 'multiple_choice', 'dropdown'].includes(type.value);
    };
    type.addEventListener('change', refresh); refresh();
});

const initializeLocaleEditor = (editor) => {
    if (editor.dataset.localeEditorInitialized === '1') return;
    editor.dataset.localeEditorInitialized = '1';
    const tabs = [...editor.querySelectorAll('[data-locale-tab]')];
    const panels = [...editor.querySelectorAll('[data-locale-panel]')];
    const activate = (locale) => {
        tabs.forEach((tab) => { const active = tab.dataset.localeTab === locale; tab.classList.toggle('is-active', active); tab.setAttribute('aria-selected', active ? 'true' : 'false'); });
        panels.forEach((panel) => { panel.hidden = panel.dataset.localePanel !== locale; });
    };
    const refreshStatus = () => panels.forEach((panel) => {
        const tab = tabs.find((item) => item.dataset.localeTab === panel.dataset.localePanel);
        const status = tab?.querySelector('[data-locale-status]');
        if (!status) return;
        const filled = [...panel.querySelectorAll('input,textarea')].some((input) => input.value.trim() !== '');
        status.textContent = filled ? status.dataset.filledLabel : status.dataset.emptyLabel;
    });
    tabs.forEach((tab) => tab.addEventListener('click', () => activate(tab.dataset.localeTab)));
    editor.addEventListener('input', refreshStatus);
    refreshStatus();
};

document.querySelectorAll('[data-locale-editor]').forEach(initializeLocaleEditor);

document.querySelectorAll('[data-option-manager]').forEach((manager) => {
    if (manager.dataset.optionManagerInitialized === '1') return;
    manager.dataset.optionManagerInitialized = '1';
    const list = manager.querySelector('[data-option-list]');
    const template = manager.querySelector('[data-option-template]');
    const addButton = manager.querySelector('[data-option-add]');
    if (!list || !template || !addButton) return;

    const maximum = Number.parseInt(manager.dataset.maxOptions || '100', 10);
    const refresh = () => { addButton.disabled = list.querySelectorAll('[data-option-row]').length >= maximum; };
    const removeScoringReference = (row) => {
        if (!row.dataset.optionValue) return;
        const form = manager.closest('form');
        form?.querySelectorAll('input[name^="scoring_rules[correct]"]').forEach((input) => {
            if (input.value !== row.dataset.optionValue) return;
            const label = input.closest('label');
            if (label) label.remove();
            else input.remove();
        });
    };
    const bindRemove = (row) => {
        if (row.dataset.optionRemoveInitialized === '1') return;
        row.dataset.optionRemoveInitialized = '1';
        row.querySelector('[data-option-remove]')?.addEventListener('click', () => {
            removeScoringReference(row);
            row.remove();
            refresh();
        });
    };
    const makeLocaleIdsUnique = (row, index) => {
        const suffix = `${manager.dataset.optionManagerKey || 'options'}-${index}`.replace(/[^a-zA-Z0-9_-]/g, '-');
        const ids = new Map();
        row.querySelectorAll('[id]').forEach((element) => {
            const oldId = element.id;
            const newId = `${oldId}-${suffix}`;
            ids.set(oldId, newId);
            element.id = newId;
        });
        row.querySelectorAll('[aria-controls]').forEach((element) => {
            const target = ids.get(element.getAttribute('aria-controls'));
            if (target) element.setAttribute('aria-controls', target);
        });
    };

    list.querySelectorAll('[data-option-row]').forEach(bindRemove);
    addButton.addEventListener('click', () => {
        if (list.querySelectorAll('[data-option-row]').length >= maximum) return;
        const fallback = list.querySelectorAll('[data-option-row]').length;
        const current = Number.parseInt(manager.dataset.nextOptionIndex || String(fallback), 10);
        const index = Number.isInteger(current) && current >= 0 ? current : fallback;
        manager.dataset.nextOptionIndex = String(index + 1);
        const wrapper = document.createElement('div');
        wrapper.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(index)).trim();
        const row = wrapper.firstElementChild;
        if (!row) return;
        makeLocaleIdsUnique(row, index);
        bindRemove(row);
        row.querySelectorAll('[data-locale-editor]').forEach(initializeLocaleEditor);
        list.appendChild(row);
        refresh();
    });
    refresh();
});

document.querySelectorAll('select[data-move-url]').forEach((select) => select.addEventListener('change', async () => {
    if (!select.value) return;
    const body = new FormData(); body.append('_token', csrf()); body.append('direction', 'section'); body.append('section_id', select.value);
    await fetch(select.dataset.moveUrl, {method: 'POST', body}); window.location.reload();
}));

document.querySelectorAll('[data-patient-select-all]').forEach((selectAll) => {
    const patients = [...document.querySelectorAll('[data-patient-select]')];
    const refresh = () => {
        const selected = patients.filter((checkbox) => checkbox.checked).length;
        selectAll.checked = patients.length > 0 && selected === patients.length;
        selectAll.indeterminate = selected > 0 && selected < patients.length;
    };
    selectAll.addEventListener('change', () => {
        patients.forEach((checkbox) => { checkbox.checked = selectAll.checked; });
        refresh();
    });
    patients.forEach((checkbox) => checkbox.addEventListener('change', refresh));
    refresh();
});

const runner = document.querySelector('#form-runner');
if (runner) {
    const form = runner.querySelector('[data-response-form]');
    const pages = [...runner.querySelectorAll('[data-page]')];
    const previous = runner.querySelector('[data-prev]');
    const next = runner.querySelector('[data-next]');
    const submit = runner.querySelector('[data-submit]');
    const status = runner.querySelector('[data-save-status]');
    const progressBar = runner.querySelector('[data-progress-bar]');
    const progressText = runner.querySelector('[data-progress-text]');
    let page = 0;
    let revision = Number(runner.dataset.revision || 0);
    let timer;
    let dirty = false;
    let activeSave = null;
    let changeSequence = 0;
    let retryPending = false;
    const conditions = JSON.parse(runner.dataset.conditions || '[]');

    const values = () => {
        const result = {};
        runner.querySelectorAll('[data-component]').forEach((component) => {
            const id = component.dataset.component;
            const controls = [...component.querySelectorAll('[data-answer]')];
            if (!controls.length) return;
            const first = controls[0];
            if (first.type === 'checkbox' && controls.length > 1) result[id] = controls.filter((x) => x.checked).map((x) => x.value);
            else if (first.type === 'checkbox') result[id] = first.checked;
            else if (first.type === 'radio') { const selected = controls.find((x) => x.checked); if (selected) result[id] = selected.value; }
            else result[id] = first.value === '' ? null : first.value;
        });
        return result;
    };

    const match = (operator, actual, rawExpected) => {
        const expected = rawExpected && typeof rawExpected === 'object' && 'value' in rawExpected ? rawExpected.value : rawExpected;
        if (operator === 'equals') return actual == expected;
        if (operator === 'not_equals') return actual != expected;
        if (operator === 'contains') return Array.isArray(actual) ? actual.includes(expected) : String(actual ?? '').includes(String(expected ?? ''));
        if (operator === 'greater_than') return Number(actual) > Number(expected);
        if (operator === 'less_than') return Number(actual) < Number(expected);
        if (operator === 'is_answered') return actual !== null && actual !== '' && (!Array.isArray(actual) || actual.length > 0);
        if (operator === 'is_not_answered') return actual === null || actual === '' || (Array.isArray(actual) && actual.length === 0);
        return false;
    };

    const conditionalVisibility = () => {
        const answerValues = values();
        const componentState = new Map([...runner.querySelectorAll('[data-component]')].map((item) => [item.dataset.component, item.dataset.defaultVisible === '1']));
        const sectionState = new Map(pages.map((item) => [item.dataset.section, item.dataset.defaultVisible === '1']));
        conditions.forEach((rule) => (rule.actions || []).forEach((action) => {
            if (action.action === 'show_component' && action.target_component_id) componentState.set(String(action.target_component_id), false);
            if (action.action === 'show_section' && action.target_section_id) sectionState.set(String(action.target_section_id), false);
        }));
        [...conditions].sort((a, b) => Number(a.priority || 0) - Number(b.priority || 0)).forEach((rule) => {
            if (!match(rule.operator, answerValues[rule.source_component_id], rule.comparison_value)) return;
            (rule.actions || []).forEach((action) => {
                const visible = action.action.startsWith('show_');
                if (action.target_component_id) componentState.set(String(action.target_component_id), visible);
                if (action.target_section_id) sectionState.set(String(action.target_section_id), visible);
            });
        });
        pages.forEach((item) => { item.dataset.conditionVisible = sectionState.get(item.dataset.section) ? '1' : '0'; });
        runner.querySelectorAll('[data-component]').forEach((item) => {
            const section = item.closest('[data-section]');
            item.hidden = !componentState.get(item.dataset.component) || section?.dataset.conditionVisible !== '1';
        });
    };

    const updateProgress = () => {
        const controls = [...runner.querySelectorAll('[data-component]:not([hidden]) [data-answer]')];
        const components = new Set(controls.map((control) => control.closest('[data-component]').dataset.component));
        const answered = new Set();
        controls.forEach((control) => { if ((['checkbox', 'radio'].includes(control.type) && control.checked) || (!['checkbox', 'radio'].includes(control.type) && control.value !== '')) answered.add(control.closest('[data-component]').dataset.component); });
        const percentage = components.size ? Math.round(answered.size / components.size * 100) : 100;
        progressBar.style.width = `${percentage}%`; progressText.textContent = `${percentage}%`;
    };

    const showPage = () => {
        conditionalVisibility();
        const visibleIndexes = pages.map((item, index) => item.dataset.conditionVisible === '1' ? index : null).filter((index) => index !== null);
        if (!visibleIndexes.includes(page)) page = visibleIndexes[0] ?? 0;
        pages.forEach((item, index) => item.hidden = index !== page || item.dataset.conditionVisible !== '1');
        const position = visibleIndexes.indexOf(page);
        previous.disabled = position <= 0; next.hidden = position === visibleIndexes.length - 1; submit.hidden = position !== visibleIndexes.length - 1;
        updateProgress(); window.scrollTo({top: 0, behavior: 'smooth'});
    };

    const setStatus = (key) => {
        const labels = {saving: runner.dataset.statusSaving, saved: runner.dataset.statusSaved, offline: runner.dataset.statusOffline, error: runner.dataset.statusSaveError};
        status.textContent = labels[key]; status.dataset.state = key;
    };

    const saveNow = async (force = false) => {
        if (activeSave) return activeSave;
        if (!dirty || (!force && runner.dataset.autosaveEnabled !== '1')) return true;
        if (!navigator.onLine) { retryPending = true; setStatus('offline'); return false; }
        const savedSequence = changeSequence;
        activeSave = (async () => { setStatus('saving');
        try {
            const response = await fetch(runner.dataset.autosaveUrl, {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf()}, body: JSON.stringify({expected_revision: revision, client_mutation_id: crypto.randomUUID(), answers: values()})});
            const data = await response.json();
            if (!response.ok) throw new Error(Object.values(data.errors || {}).flat().join(' ') || data.message || 'Save failed');
            revision = Number(data.revision); runner.dataset.revision = String(revision); dirty = changeSequence !== savedSequence; retryPending = false; setStatus(dirty ? 'saving' : 'saved');
            if (data.consent_refused) { pages.slice(1).forEach((item) => item.hidden = true); }
            return true;
        } catch (error) { retryPending = true; setStatus(navigator.onLine ? 'error' : 'offline'); status.title = error.message; return false; }
        })();
        try { return await activeSave; } finally { activeSave = null; }
    };

    const queueSave = () => { dirty = true; changeSequence++; clearTimeout(timer); timer = setTimeout(saveNow, 700); conditionalVisibility(); pages.forEach((item,index)=>item.hidden=index!==page||item.dataset.conditionVisible!=='1'); updateProgress(); };
    form.addEventListener('input', queueSave); form.addEventListener('change', queueSave);
    document.querySelectorAll('[data-locale-form]').forEach((localeForm) => localeForm.addEventListener('submit', async (event) => {
        if (localeForm.dataset.saveAcknowledged === '1') return;
        event.preventDefault(); clearTimeout(timer);
        while (activeSave || dirty) { if (!(await saveNow(true))) return; }
        localeForm.dataset.saveAcknowledged = '1'; localeForm.requestSubmit();
    }));
    window.addEventListener('online', () => { if (retryPending || dirty) saveNow(); });
    window.addEventListener('offline', () => setStatus('offline'));
    window.addEventListener('beforeunload', (event) => { if (dirty || activeSave) { event.preventDefault(); event.returnValue = ''; } });
    const visibleIndexes = () => pages.map((item,index)=>item.dataset.conditionVisible==='1'?index:null).filter((index)=>index!==null);
    const pageIsValid = () => { const invalid=[...pages[page].querySelectorAll('[data-answer]')].find((control)=>!control.closest('[data-component]').hidden&&!control.checkValidity()); if(invalid){invalid.reportValidity();return false;}return true; };
    previous.addEventListener('click', () => { const indexes=visibleIndexes();page=indexes[Math.max(0,indexes.indexOf(page)-1)]??page;showPage(); });
    next.addEventListener('click', async () => { if (!pageIsValid()) return; if (!(await saveNow())) return; const indexes=visibleIndexes();page=indexes[Math.min(indexes.length-1,indexes.indexOf(page)+1)]??page;showPage(); });

    const finalize = async () => {
        clearTimeout(timer);
        while (activeSave || (runner.dataset.autosaveEnabled === '1' && dirty)) { if (!(await saveNow())) return; }
        submit.disabled = true;
        const response = await fetch(runner.dataset.finalizeUrl, {method: 'POST', headers: {'Content-Type':'application/json','Accept': 'application/json', 'X-CSRF-TOKEN': csrf()}, body:JSON.stringify({expected_revision:revision,client_mutation_id:crypto.randomUUID(),answers:values()})});
        const data = await response.json(); if (!response.ok) { status.textContent = Object.values(data.errors || {}).flat().join(' ') || data.message; status.dataset.state = 'error'; return; }
        revision=Number(data.revision);dirty = false; window.location.href = data.redirect;
    };
    form.addEventListener('submit', async (event) => { event.preventDefault(); await finalize(); submit.disabled=false; });

    if (runner.dataset.deadline) {
        const timerElement = runner.querySelector('[data-timer]');
        const tick = () => { const seconds = Math.max(0, Math.floor((new Date(runner.dataset.deadline).getTime() - Date.now()) / 1000)); timerElement.textContent = `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`; if (seconds <= 0) { clearInterval(interval); finalize(); } };
        let interval; tick(); interval = setInterval(tick, 1000);
    }
    showPage();
}
