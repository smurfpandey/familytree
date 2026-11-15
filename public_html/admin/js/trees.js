/**
 * admin/js/trees.js — switching between trees, creating one, deleting one.
 *
 * An account can belong to several trees. The active tree lives in the PHP
 * session, so every switch does a full reload; no page keeps stale members,
 * users or media from the previous tree.
 */

async function switchTree(tenantId) {
    if (!tenantId || (typeof ADMIN_TREE !== 'undefined' && ADMIN_TREE && ADMIN_TREE.id === tenantId)) return;
    try {
        const res = await csrfFetch('tree-api.php', {
            method: 'POST',
            body: JSON.stringify({ action: 'switch', tenant_id: tenantId })
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || data.error) {
            alert(data.error || 'Could not switch tree.');
            if (typeof ADMIN_TREE !== 'undefined' && ADMIN_TREE) {
                const sel = document.getElementById('treeSwitcher');
                if (sel) sel.value = ADMIN_TREE.id;
            }
            return;
        }
        window.location.reload();
    } catch (e) {
        console.error('switch tree', e);
        alert('Could not switch tree.');
    }
}

function openNewTreeDialog() {
    const ov = document.getElementById('newTreeOverlay');
    if (!ov) return;
    ov.style.display = 'flex';
    const err = document.getElementById('newTreeError');
    if (err) err.textContent = '';
    const name = document.getElementById('newTreeName');
    if (name) { name.value = ''; name.focus(); }
    const slug = document.getElementById('newTreeSlug');
    if (slug) slug.value = '';
}

function closeNewTreeDialog() {
    const ov = document.getElementById('newTreeOverlay');
    if (ov) ov.style.display = 'none';
}

async function createTree(e) {
    if (e) e.preventDefault();
    const nameEl = document.getElementById('newTreeName');
    const slugEl = document.getElementById('newTreeSlug');
    const errEl  = document.getElementById('newTreeError');
    const btn    = document.getElementById('newTreeSubmit');
    const name   = (nameEl?.value || '').trim();
    const slug   = (slugEl?.value || '').trim();

    if (!name) {
        if (errEl) errEl.textContent = 'Give the family a name.';
        nameEl?.focus();
        return;
    }

    if (btn) { btn.disabled = true; btn.textContent = 'Creating…'; }
    if (errEl) errEl.textContent = '';
    try {
        const res = await csrfFetch('tree-api.php', {
            method: 'POST',
            body: JSON.stringify({ action: 'create', name, slug, switch: false })
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || data.error) {
            if (errEl) errEl.textContent = data.error || 'Could not create the tree.';
            return;
        }
        window.location.reload();
    } catch (err) {
        console.error('create tree', err);
        if (errEl) errEl.textContent = 'Could not create the tree. Please try again.';
    } finally {
        if (btn) { btn.disabled = false; btn.textContent = 'Create tree'; }
    }
}

async function deleteCurrentTree() {
    if (typeof ADMIN_TREE === 'undefined' || !ADMIN_TREE || !ADMIN_TREE.id) return;
    const input = document.getElementById('deleteTreeConfirm');
    const msg   = document.getElementById('deleteTreeMsg');
    const typed = (input?.value || '').trim();
    if (typed !== (ADMIN_TREE.display_name || '')) {
        if (msg) { msg.textContent = 'Type the tree name exactly to confirm.'; msg.className = 'dh-msg bad'; }
        input?.focus();
        return;
    }
    if (!confirm('Delete “' + ADMIN_TREE.display_name + '” and everything in it? This cannot be undone.')) return;

    if (msg) { msg.textContent = 'Deleting…'; msg.className = 'dh-msg'; }
    try {
        const res = await csrfFetch('tree-api.php', {
            method: 'POST',
            body: JSON.stringify({ action: 'delete', tenant_id: ADMIN_TREE.id, confirm: typed })
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || data.error) {
            if (msg) { msg.textContent = data.error || 'Could not delete the tree.'; msg.className = 'dh-msg bad'; }
            return;
        }
        window.location.reload();
    } catch (err) {
        console.error('delete tree', err);
        if (msg) { msg.textContent = 'Could not delete the tree.'; msg.className = 'dh-msg bad'; }
    }
}

/* ── Instance → Trees ─────────────────────────────────────────────
 * Every active tree on the installation. A tree owner can hand over
 * or delete the trees they own; anyone can open a tree they belong to.
 */
let INSTANCE_TREES = [];
let _transferTreeId = null;

async function loadInstanceTrees() {
    const tbody = document.querySelector('#instanceTreesTable tbody');
    if (!tbody) return;
    try {
        const res = await csrfFetch('tree-api.php?scope=instance');
        const data = await res.json();
        if (!res.ok || data.error) throw new Error(data.error || 'Could not load trees.');
        INSTANCE_TREES = data.trees || [];
        renderInstanceTrees(INSTANCE_TREES);
    } catch (err) {
        console.error('load instance trees', err);
        tbody.innerHTML = '<tr><td colspan="6" class="dh-msg bad">Could not load the trees.</td></tr>';
    }
}

function renderInstanceTrees(trees) {
    const tbody = document.querySelector('#instanceTreesTable tbody');
    if (!tbody) return;
    tbody.innerHTML = '';

    const badge = document.getElementById('instanceTreeCountBadge');
    if (badge) badge.textContent = trees.length;

    if (!trees.length) {
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = 6;
        td.className = 'dh-hint';
        td.textContent = 'No trees yet. Plant the first one.';
        tr.appendChild(td);
        tbody.appendChild(tr);
        return;
    }

    trees.forEach(function (tree) {
        const tr = document.createElement('tr');

        // Tree name (+ "Open now" badge)
        const name = document.createElement('td');
        const strong = document.createElement('strong');
        strong.className = 'tree-name';
        strong.textContent = tree.display_name;
        name.appendChild(strong);
        if (tree.is_active) {
            const b = document.createElement('span');
            b.className = 'badge badge-green';
            b.style.marginLeft = '8px';
            b.textContent = 'Open now';
            name.appendChild(b);
        }
        tr.appendChild(name);

        // Public link
        const link = document.createElement('td');
        link.className = 'td-muted';
        link.textContent = '/tree/' + tree.slug;
        tr.appendChild(link);

        // Visibility badge
        const vis = document.createElement('td');
        const v = document.createElement('span');
        v.className = 'badge ' + (tree.visibility === 'public' ? 'badge-green'
            : tree.visibility === 'unlisted' ? 'badge-yellow' : 'badge-gray');
        v.textContent = tree.visibility.charAt(0).toUpperCase() + tree.visibility.slice(1);
        vis.appendChild(v);
        tr.appendChild(vis);

        // Main owner (+ email underneath)
        const owner = document.createElement('td');
        owner.textContent = tree.owner_name || '—';
        if (tree.owner_name && tree.owner_email) {
            const sub = document.createElement('span');
            sub.className = 'tree-sub';
            sub.textContent = tree.owner_email;
            owner.appendChild(sub);
        }
        tr.appendChild(owner);

        // People with access
        const users = document.createElement('td');
        users.textContent = tree.user_count + (tree.user_count === 1 ? ' account' : ' accounts');
        if (tree.owner_count > 1) {
            const sub = document.createElement('span');
            sub.className = 'tree-sub';
            sub.textContent = tree.owner_count + ' owners';
            users.appendChild(sub);
        }
        tr.appendChild(users);

        // Actions
        const actions = document.createElement('td');
        actions.className = 'td-actions';

        if (tree.my_role) {
            if (tree.is_active) {
                const current = document.createElement('span');
                current.className = 'badge badge-gray';
                current.textContent = 'Current';
                actions.appendChild(current);
            } else {
                const open = document.createElement('button');
                open.type = 'button';
                open.className = 'btn btn-soft btn-sm';
                open.textContent = 'Open';
                open.onclick = function () { switchTree(tree.id); };
                actions.appendChild(open);
            }
        }

        if (tree.can_manage) {
            const give = document.createElement('button');
            give.type = 'button';
            give.className = 'btn btn-ghost btn-sm';
            give.textContent = 'Make owner';
            give.onclick = function () { openTransferTreeDialog(tree); };
            actions.appendChild(give);

            const del = document.createElement('button');
            del.type = 'button';
            del.className = 'btn btn-danger-ghost btn-sm';
            del.textContent = 'Delete';
            del.onclick = function () { deleteInstanceTree(tree); };
            actions.appendChild(del);
        }

        tr.appendChild(actions);
        tbody.appendChild(tr);
    });
}

async function openTransferTreeDialog(tree) {
    _transferTreeId = tree.id;
    const overlay = document.getElementById('transferTreeOverlay');
    const select = document.getElementById('transferTreeUser');
    const msg = document.getElementById('transferTreeMsg');
    const title = document.getElementById('transferTreeTitle');
    const desc = document.getElementById('transferTreeDesc');
    const submit = document.getElementById('transferTreeSubmit');
    if (!overlay || !select) return;

    if (title) title.textContent = 'Make an owner of “' + tree.display_name + '”';
    if (desc) desc.textContent = 'Choose an account to become an owner. The current owners stay owners.';
    if (msg) { msg.textContent = ''; msg.className = 'ob-hint'; }
    if (submit) submit.disabled = true;
    select.innerHTML = '<option value="">Loading…</option>';
    overlay.style.display = 'flex';

    try {
        const res = await csrfFetch('tree-api.php?tree_users=' + encodeURIComponent(tree.id));
        const data = await res.json();
        if (!res.ok || data.error) throw new Error(data.error || 'Could not load accounts.');
        select.innerHTML = '';
        (data.users || []).forEach(function (u) {
            const opt = document.createElement('option');
            opt.value = u.id;
            opt.textContent = u.display_name + ' · ' + u.email + (u.role === 'owner' ? ' · already an owner' : '');
            select.appendChild(opt);
        });
        if (!select.options.length) {
            select.innerHTML = '<option value="">No accounts on this instance</option>';
        }
        if (submit) submit.disabled = false;
    } catch (err) {
        console.error('load tree accounts', err);
        select.innerHTML = '<option value="">Could not load accounts</option>';
        if (msg) { msg.textContent = err.message || 'Could not load accounts.'; msg.className = 'ob-hint'; }
    }
}

function closeTransferTreeDialog() {
    const overlay = document.getElementById('transferTreeOverlay');
    if (overlay) overlay.style.display = 'none';
    _transferTreeId = null;
}

async function submitTreeTransfer() {
    if (!_transferTreeId) return;
    const select = document.getElementById('transferTreeUser');
    const msg = document.getElementById('transferTreeMsg');
    const submit = document.getElementById('transferTreeSubmit');
    const userId = Number(select?.value || 0);
    if (!userId) {
        if (msg) msg.textContent = 'Choose an account.';
        return;
    }
    if (submit) { submit.disabled = true; submit.textContent = 'Saving…'; }
    try {
        const res = await csrfFetch('tree-api.php', {
            method: 'POST',
            body: JSON.stringify({ action: 'transfer', tenant_id: _transferTreeId, user_id: userId })
        });
        const data = await res.json().catch(function () { return {}; });
        if (!res.ok || data.error) {
            if (msg) msg.textContent = data.error || 'Could not make that account an owner.';
            return;
        }
        closeTransferTreeDialog();
        await loadInstanceTrees();
    } catch (err) {
        console.error('transfer tree', err);
        if (msg) msg.textContent = 'Could not make that account an owner.';
    } finally {
        if (submit) { submit.disabled = false; submit.textContent = 'Make owner'; }
    }
}

async function deleteInstanceTree(tree) {
    const typed = prompt('Type the tree name to delete “' + tree.display_name + '” and everything in it:');
    if (typed === null) return;
    if (typed.trim() !== tree.display_name) {
        alert('Type the tree name exactly to confirm.');
        return;
    }
    if (!confirm('Delete “' + tree.display_name + '” and everything in it? This cannot be undone.')) return;

    try {
        const res = await csrfFetch('tree-api.php', {
            method: 'POST',
            body: JSON.stringify({ action: 'delete', tenant_id: tree.id, confirm: typed.trim() })
        });
        const data = await res.json().catch(function () { return {}; });
        if (!res.ok || data.error) {
            alert(data.error || 'Could not delete the tree.');
            return;
        }
        if (data.logged_out) { window.location.href = '/admin/'; return; }
        window.location.reload();
    } catch (err) {
        console.error('delete tree', err);
        alert('Could not delete the tree.');
    }
}
