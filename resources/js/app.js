const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content;

document.querySelectorAll('[data-confirm]').forEach((form) => form.addEventListener('submit', (event) => {
    if (!window.confirm(form.dataset.confirm)) event.preventDefault();
}));

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

document.querySelectorAll('select[data-move-url]').forEach((select) => select.addEventListener('change', async () => {
    if (!select.value) return;
    const body = new FormData(); body.append('_token', csrf()); body.append('direction', 'section'); body.append('section_id', select.value);
    await fetch(select.dataset.moveUrl, {method: 'POST', body}); window.location.reload();
}));

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
        const labels = {saving: document.documentElement.lang === 'lv' ? 'Saglabā…' : 'Saving…', saved: document.documentElement.lang === 'lv' ? 'Saglabāts' : 'Saved', offline: document.documentElement.lang === 'lv' ? 'Bezsaistē' : 'Offline', error: document.documentElement.lang === 'lv' ? 'Saglabāšanas kļūda' : 'Save error'};
        status.textContent = labels[key]; status.dataset.state = key;
    };

    const saveNow = async () => {
        if (activeSave) return activeSave;
        if (runner.dataset.autosaveEnabled !== '1' || !dirty) return true;
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
