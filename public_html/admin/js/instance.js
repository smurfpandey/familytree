// admin/js/instance.js - instance-wide settings (not one tree's settings).

let INSTANCE_DATA = null;

function instanceThemeLabel(id) {
    if (window.FT_TREE_THEMES && window.FT_TREE_THEMES[id]) return window.FT_TREE_THEMES[id].name;
    return id.charAt(0).toUpperCase() + id.slice(1);
}

async function loadInstanceSettings() {
    try {
        const res = await csrfFetch('instance-api.php');
        const data = await res.json();
        if (!res.ok || data.error) throw new Error(data.error || 'Could not load instance settings.');
        INSTANCE_DATA = data;
        renderInstanceSettings();
    } catch (err) {
        console.error('load instance settings', err);
        const msg = document.getElementById('instanceSaveMsg');
        if (msg) { msg.textContent = err.message || 'Could not load instance settings.'; msg.className = 'dh-msg bad'; }
    }
}

function renderInstanceSettings() {
    if (!INSTANCE_DATA) return;
    const s = INSTANCE_DATA.settings;

    const name = document.getElementById('instanceName');
    if (name) name.value = s.instance_name || '';

    const theme = document.getElementById('instanceNewTheme');
    if (theme) {
        theme.innerHTML = '';
        (INSTANCE_DATA.looks || []).forEach(function (id) {
            const opt = document.createElement('option');
            opt.value = id;
            opt.textContent = instanceThemeLabel(id);
            theme.appendChild(opt);
        });
        theme.value = s.new_tree_theme || 'heritage';
    }

    const tree = document.getElementById('instanceDefaultTree');
    if (tree) {
        tree.innerHTML = '<option value="">Oldest active tree</option>';
        (INSTANCE_DATA.trees || []).forEach(function (t) {
            const opt = document.createElement('option');
            opt.value = t.id;
            opt.textContent = t.display_name + ' · /tree/' + t.slug + ' · ' + t.visibility;
            tree.appendChild(opt);
        });
        tree.value = s.default_tenant_id || '';
    }
    const hint = document.getElementById('instanceDefaultTreeHint');
    if (hint) {
        const effective = (INSTANCE_DATA.trees || []).find(function (t) { return t.id === INSTANCE_DATA.effective_default_tenant_id; });
        hint.textContent = effective
            ? 'Visitors currently see “' + effective.display_name + '”.'
            : 'There is no active tree yet.';
    }

    const vis = document.getElementById('instanceNewVisibility');
    if (vis) vis.value = s.new_tree_visibility || 'private';

    const hindu = document.getElementById('instanceNewHindu');
    if (hindu) hindu.checked = !!s.new_tree_hindu_calendar;

    const body = document.getElementById('instancePrivacyBody');
    if (body) {
        body.innerHTML = '';
        const labels = INSTANCE_DATA.field_labels || {};
        Object.keys(INSTANCE_DATA.field_defaults || {}).forEach(function (field) {
            const current = (s.new_tree_privacy && s.new_tree_privacy[field]) || INSTANCE_DATA.field_defaults[field];
            const tr = document.createElement('tr');
            const th = document.createElement('td');
            th.textContent = labels[field] || field;
            tr.appendChild(th);
            ['public', 'members', 'private'].forEach(function (value) {
                const td = document.createElement('td');
                const input = document.createElement('input');
                input.type = 'radio';
                input.name = 'instance_privacy_' + field;
                input.value = value;
                input.checked = current === value;
                td.appendChild(input);
                tr.appendChild(td);
            });
            body.appendChild(tr);
        });
    }
}

async function saveInstanceSettings() {
    if (!INSTANCE_DATA) return;
    const btn = document.getElementById('saveInstanceBtn');
    const msg = document.getElementById('instanceSaveMsg');
    const name = (document.getElementById('instanceName')?.value || '').trim();
    if (!name) {
        if (msg) { msg.textContent = 'Give this instance a name.'; msg.className = 'dh-msg bad'; }
        document.getElementById('instanceName')?.focus();
        return;
    }

    const privacy = {};
    Object.keys(INSTANCE_DATA.field_defaults || {}).forEach(function (field) {
        const picked = document.querySelector('input[name="instance_privacy_' + field + '"]:checked');
        if (picked) privacy[field] = picked.value;
    });

    const payload = {
        instance_name: name,
        default_tenant_id: document.getElementById('instanceDefaultTree')?.value || '',
        new_tree_visibility: document.getElementById('instanceNewVisibility')?.value || 'private',
        new_tree_theme: document.getElementById('instanceNewTheme')?.value || 'heritage',
        new_tree_hindu_calendar: !!document.getElementById('instanceNewHindu')?.checked,
        new_tree_privacy: privacy
    };

    if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }
    if (msg) { msg.textContent = ''; msg.className = 'dh-msg'; }
    try {
        const res = await csrfFetch('instance-api.php', {
            method: 'POST',
            body: JSON.stringify(payload)
        });
        const data = await res.json().catch(function () { return {}; });
        if (!res.ok || data.error) {
            if (msg) { msg.textContent = data.error || 'Could not save these settings.'; msg.className = 'dh-msg bad'; }
            return;
        }
        if (msg) { msg.textContent = 'Saved'; msg.className = 'dh-msg ok'; }
        const logo = document.querySelector('.sb-logo-text');
        if (logo) logo.textContent = name;
        const pageTitle = document.getElementById('topBarTitle')?.textContent || 'Admin';
        document.title = pageTitle + ' · ' + name;
        await loadInstanceSettings();
    } catch (err) {
        console.error('save instance settings', err);
        if (msg) { msg.textContent = 'Could not save these settings.'; msg.className = 'dh-msg bad'; }
    } finally {
        if (btn) { btn.disabled = false; btn.textContent = 'Save Instance Settings'; }
    }
}
