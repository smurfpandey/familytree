// admin/js/users.js - accounts on the whole instance and their tree access.

let USER_TREES = [];
let USER_DETAIL = null;
let MEMBER_CACHE = {};

async function fetchUsers() {
    try {
        const res = await csrfFetch('users-api.php');
        const data = await res.json();
        if (!res.ok || data.error) throw new Error(data.error || 'Could not load accounts.');
        users = data.accounts || [];
        USER_TREES = data.trees || [];
        applyUserFilters();
        renderCreateTreeOptions();
    } catch (err) {
        console.error('Failed to fetch users', err);
        const tbody = document.querySelector('#usersTable tbody');
        if (tbody) tbody.innerHTML = '<tr><td colspan="4" class="dh-msg bad">Could not load accounts.</td></tr>';
    }
}

function treeAccessLabel(u) {
    if (!u.assignments || !u.assignments.length) return 'No tree access';
    return u.assignments.map(function (a) { return a.tree_name; }).join(', ');
}

function applyUserFilters() {
    const input = document.getElementById('userSearch');
    const text = input ? input.value.trim().toLowerCase() : '';
    filteredUsers = users.filter(function (u) {
        if (!text) return true;
        return [u.id, u.email || '', u.display_name || '', treeAccessLabel(u)]
            .join(' ').toLowerCase().includes(text);
    });
    renderUsersTable();
}

function renderUsersTable() {
    const tbody = document.querySelector('#usersTable tbody');
    if (!tbody) return;
    tbody.innerHTML = '';

    const rows = [...filteredUsers];
    rows.sort(function (a, b) {
        const col = userSortState.column;
        let va, vb;
        if (col === 'email') { va = (a.email || '').toLowerCase(); vb = (b.email || '').toLowerCase(); }
        else if (col === 'trees') { va = treeAccessLabel(a).toLowerCase(); vb = treeAccessLabel(b).toLowerCase(); }
        else if (col === 'last_login_at') { va = a.last_login_at || ''; vb = b.last_login_at || ''; }
        else { va = (a.display_name || '').toLowerCase(); vb = (b.display_name || '').toLowerCase(); }
        if (va < vb) return userSortState.dir === 'asc' ? -1 : 1;
        if (va > vb) return userSortState.dir === 'asc' ? 1 : -1;
        return 0;
    });

    rows.forEach(function (u) {
        const tr = document.createElement('tr');
        const name = document.createElement('td');
        name.textContent = u.display_name || '';
        if (u.is_self) {
            const b = document.createElement('span');
            b.className = 'badge badge-gray';
            b.style.marginLeft = '8px';
            b.textContent = 'You';
            name.appendChild(b);
        }
        tr.appendChild(name);

        const email = document.createElement('td');
        email.textContent = u.email || '';
        tr.appendChild(email);

        const trees = document.createElement('td');
        trees.textContent = treeAccessLabel(u);
        if (!u.assignments || !u.assignments.length) trees.className = 'dh-hint';
        tr.appendChild(trees);

        const last = document.createElement('td');
        last.textContent = u.last_login_at || '';
        tr.appendChild(last);

        tr.onclick = function () { editUser(u.id); };
        tbody.appendChild(tr);
    });

    const badge = document.getElementById('userCountBadge');
    if (badge) badge.textContent = rows.length;
}

/* ── New account ─────────────────────────────────────────────── */

function renderCreateTreeOptions() {
    const wrap = document.getElementById('newAccountTrees');
    if (!wrap) return;
    wrap.innerHTML = '';
    if (!USER_TREES.length) {
        const p = document.createElement('p');
        p.className = 'dh-hint';
        p.textContent = 'You do not own a tree yet, so there is no tree to assign.';
        wrap.appendChild(p);
        return;
    }
    USER_TREES.forEach(function (t) {
        const row = document.createElement('div');
        row.style.display = 'flex';
        row.style.alignItems = 'center';
        row.style.gap = '10px';
        row.style.flexWrap = 'wrap';

        const label = document.createElement('label');
        label.style.display = 'flex';
        label.style.alignItems = 'center';
        label.style.gap = '8px';
        label.style.minWidth = '220px';
        const box = document.createElement('input');
        box.type = 'checkbox';
        box.setAttribute('data-create-tree', t.id);
        box.checked = !!(typeof ADMIN_TREE !== 'undefined' && ADMIN_TREE && ADMIN_TREE.id === t.id);
        label.appendChild(box);
        label.appendChild(document.createTextNode(t.display_name));
        row.appendChild(label);

        const role = document.createElement('select');
        role.className = 'form-control';
        role.style.maxWidth = '150px';
        role.setAttribute('data-create-role', t.id);
        ['viewer', 'editor', 'owner'].forEach(function (value) {
            const opt = document.createElement('option');
            opt.value = value;
            opt.textContent = value.charAt(0).toUpperCase() + value.slice(1);
            role.appendChild(opt);
        });
        row.appendChild(role);
        wrap.appendChild(row);
    });
}

function focusNewAccount() {
    closeUserDetail();
    document.getElementById('userCreateWrap')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    setTimeout(function () { document.getElementById('newAccountName')?.focus(); }, 250);
}

function resetCreateAccount() {
    const form = document.getElementById('userCreateForm');
    if (form) form.reset();
    const msg = document.getElementById('createAccountMsg');
    if (msg) { msg.textContent = ''; msg.className = 'dh-msg'; }
    renderCreateTreeOptions();
}

async function createAccount(e) {
    e.preventDefault();
    const msg = document.getElementById('createAccountMsg');
    const name = (document.getElementById('newAccountName')?.value || '').trim();
    const email = (document.getElementById('newAccountEmail')?.value || '').trim();
    const password = document.getElementById('newAccountPassword')?.value || '';

    const assignments = [];
    document.querySelectorAll('[data-create-tree]').forEach(function (box) {
        if (!box.checked) return;
        const tenantId = box.getAttribute('data-create-tree');
        const role = document.querySelector('[data-create-role="' + CSS.escape(tenantId) + '"]')?.value || 'viewer';
        assignments.push({ tenant_id: tenantId, role: role });
    });

    if (msg) { msg.textContent = 'Creating…'; msg.className = 'dh-msg'; }
    try {
        const res = await csrfFetch('users-api.php', {
            method: 'POST',
            body: JSON.stringify({ action: 'create', display_name: name, email: email, password: password, assignments: assignments })
        });
        const data = await res.json().catch(function () { return {}; });
        if (!res.ok || data.error) {
            if (msg) { msg.textContent = data.error || 'Could not create the account.'; msg.className = 'dh-msg bad'; }
            return;
        }
        resetCreateAccount();
        await fetchUsers();
        if (data.id) await editUser(data.id);
    } catch (err) {
        console.error('create account', err);
        if (msg) { msg.textContent = 'Could not create the account.'; msg.className = 'dh-msg bad'; }
    }
}

/* ── One account's access ────────────────────────────────────── */

async function editUser(id) {
    try {
        const res = await csrfFetch('users-api.php?id=' + encodeURIComponent(id));
        const u = await res.json();
        if (!res.ok || u.error) { alert(u.error || 'Account not found'); return; }
        USER_DETAIL = u;
        fillUserDetail(u);
    } catch (err) {
        console.error('Edit user error', err);
        alert('Failed to load the account');
    }
}

function fillUserDetail(u) {
    const wrap = document.getElementById('userDetailWrap');
    if (!wrap) return;
    wrap.style.display = 'block';
    // Hide the "New account" form while editing
    const createWrap = document.getElementById('userCreateWrap');
    if (createWrap) { createWrap.style.display = 'none'; createWrap.hidden = true; }

    const name = document.getElementById('userDetailName');
    if (name) name.textContent = u.display_name || 'Account';
    const email = document.getElementById('userDetailEmail');
    if (email) email.textContent = u.email || '';
    const note = document.getElementById('userDetailNote');
    if (note) {
        note.textContent = u.is_self
            ? 'This is your account. Change your own name, email and password in Profile.'
            : 'Name, email and password belong to this person; they change them in Profile. You manage which of your trees they can open.';
    }

    renderAssignments(u);

    const treeSel = document.getElementById('assignTree');
    if (treeSel) {
        const assigned = new Set((u.assignments || []).map(function (a) { return a.tenant_id; }));
        const available = USER_TREES.filter(function (t) { return !assigned.has(t.id); });
        treeSel.innerHTML = '';
        available.forEach(function (t) {
            const opt = document.createElement('option');
            opt.value = t.id;
            opt.textContent = t.display_name;
            treeSel.appendChild(opt);
        });
        if (!available.length) {
            const opt = document.createElement('option');
            opt.value = '';
            opt.textContent = USER_TREES.length ? 'Already has access to every tree you own' : 'No tree you own';
            treeSel.appendChild(opt);
        }
    }
    loadAssignMembers();

    const del = document.getElementById('deleteAccountBtn');
    if (del) del.style.display = u.can_delete ? 'inline-flex' : 'none';
    wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function memberOptionLabel(m) {
    if (typeof buildMemberLabel === 'function') return buildMemberLabel(m.name, m.nickname, m.id);
    return (m.name || '') + (m.nickname ? ' (' + m.nickname + ')' : '');
}

async function membersForTree(tenantId) {
    if (MEMBER_CACHE[tenantId]) return MEMBER_CACHE[tenantId];
    const res = await csrfFetch('users-api.php?tree_members=' + encodeURIComponent(tenantId));
    const data = await res.json();
    if (!res.ok || data.error) throw new Error(data.error || 'Could not load people.');
    MEMBER_CACHE[tenantId] = data.members || [];
    return MEMBER_CACHE[tenantId];
}

function fillMemberSelect(select, members, selected) {
    select.innerHTML = '<option value="">None</option>';
    members.forEach(function (m) {
        const opt = document.createElement('option');
        opt.value = m.id;
        opt.textContent = memberOptionLabel(m);
        select.appendChild(opt);
    });
    select.value = selected ? String(selected) : '';
}

async function loadAssignMembers() {
    const treeSel = document.getElementById('assignTree');
    const memberSel = document.getElementById('assignMember');
    if (!treeSel || !memberSel || !treeSel.value) return;
    memberSel.innerHTML = '<option value="">Loading…</option>';
    try {
        const members = await membersForTree(treeSel.value);
        fillMemberSelect(memberSel, members, null);
    } catch (err) {
        console.error('load tree members', err);
        memberSel.innerHTML = '<option value="">Could not load people</option>';
    }
}

function renderAssignments(u) {
    const wrap = document.getElementById('userAssignments');
    if (!wrap) return;
    wrap.innerHTML = '';

    if (!u.assignments || !u.assignments.length) {
        const p = document.createElement('p');
        p.className = 'dh-hint';
        p.textContent = 'This account has no tree access yet.';
        wrap.appendChild(p);
    }

    const other = [];
    u.assignments.forEach(function (a) {
        if (!a.editable) { other.push(a.tree_name); return; }

        const row = document.createElement('div');
        row.style.border = '1px solid var(--border)';
        row.style.borderRadius = '10px';
        row.style.padding = '10px 12px';
        row.style.display = 'flex';
        row.style.flexWrap = 'wrap';
        row.style.gap = '10px';
        row.style.alignItems = 'center';
        row.setAttribute('data-assignment', a.tenant_id);

        const tree = document.createElement('strong');
        tree.style.minWidth = '140px';
        tree.textContent = a.tree_name;
        row.appendChild(tree);

        const role = document.createElement('select');
        role.className = 'form-control';
        role.style.maxWidth = '130px';
        role.setAttribute('data-assign-role', a.tenant_id);
        ['viewer', 'editor', 'owner'].forEach(function (value) {
            const opt = document.createElement('option');
            opt.value = value;
            opt.textContent = value.charAt(0).toUpperCase() + value.slice(1);
            role.appendChild(opt);
        });
        role.value = a.role || 'viewer';
        role.onchange = function () { saveAssignmentRow(u.id, a.tenant_id); };
        row.appendChild(role);

        const member = document.createElement('select');
        member.className = 'form-control';
        member.style.maxWidth = '220px';
        member.setAttribute('data-assign-member', a.tenant_id);
        member.dataset.loaded = '0';
        member.dataset.current = a.member_id || '';
        member.innerHTML = '<option value="">Loading…</option>';
        member.onchange = function () { saveAssignmentRow(u.id, a.tenant_id); };
        row.appendChild(member);
        membersForTree(a.tenant_id).then(function (members) {
            fillMemberSelect(member, members, a.member_id);
            member.dataset.loaded = '1';
        }).catch(function () {
            member.innerHTML = '<option value="">Could not load people</option>';
        });

        const perms = a.permissions || {};
        [
            ['can_edit_members', 'Edit people'],
            ['can_view_stats', 'Stats'],
            ['can_manage_media', 'Media']
        ].forEach(function (pair) {
            const label = document.createElement('label');
            label.style.display = 'flex';
            label.style.alignItems = 'center';
            label.style.gap = '5px';
            label.style.fontSize = '12px';
            const box = document.createElement('input');
            box.type = 'checkbox';
            box.checked = !!perms[pair[0]];
            box.setAttribute('data-assign-perm', a.tenant_id + ':' + pair[0]);
            box.onchange = function () { saveAssignmentRow(u.id, a.tenant_id); };
            label.appendChild(box);
            label.appendChild(document.createTextNode(pair[1]));
            row.appendChild(label);
        });

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn btn-danger-ghost btn-sm';
        remove.style.marginLeft = 'auto';
        remove.textContent = 'Remove access';
        remove.onclick = function () { removeAccess(u.id, a.tenant_id, a.tree_name); };
        row.appendChild(remove);

        wrap.appendChild(row);
    });

    const otherEl = document.getElementById('userOtherAccess');
    if (otherEl) {
        otherEl.textContent = other.length
            ? 'Also has access to ' + other.join(', ') + '; those trees are managed by their own owners.'
            : '';
    }
}

function assignmentPayload(userId, tenantId) {
    const role = document.querySelector('[data-assign-role="' + CSS.escape(tenantId) + '"]')?.value || 'viewer';
    const memberEl = document.querySelector('[data-assign-member="' + CSS.escape(tenantId) + '"]');
    const memberId = memberEl
        ? (memberEl.dataset.loaded === '1' ? (memberEl.value || null) : (memberEl.dataset.current || null))
        : null;
    const permissions = {};
    ['can_edit_members', 'can_view_stats', 'can_manage_media'].forEach(function (key) {
        const box = document.querySelector('[data-assign-perm="' + CSS.escape(tenantId + ':' + key) + '"]');
        permissions[key] = !!(box && box.checked);
    });
    return {
        action: 'assign',
        user_id: userId,
        tenant_id: tenantId,
        role: role,
        member_id: memberId ? Number(memberId) : null,
        permissions: permissions
    };
}

async function saveAssignmentRow(userId, tenantId) {
    try {
        const res = await csrfFetch('users-api.php', {
            method: 'POST',
            body: JSON.stringify(assignmentPayload(userId, tenantId))
        });
        const data = await res.json().catch(function () { return {}; });
        if (!res.ok || data.error) {
            alert(data.error || 'Could not save that access.');
            await editUser(userId);
            return;
        }
        await fetchUsers();
        if (USER_DETAIL && USER_DETAIL.id === userId) await editUser(userId);
    } catch (err) {
        console.error('save assignment', err);
        alert('Could not save that access.');
    }
}

async function addAssignment() {
    if (!USER_DETAIL) return;
    const treeId = document.getElementById('assignTree')?.value || '';
    const msg = document.getElementById('assignMsg');
    if (!treeId) {
        if (msg) { msg.textContent = 'You do not own a tree to assign.'; msg.className = 'dh-msg bad'; }
        return;
    }
    const role = document.getElementById('assignRole')?.value || 'viewer';
    const memberId = document.getElementById('assignMember')?.value || '';
    const payload = {
        action: 'assign',
        user_id: USER_DETAIL.id,
        tenant_id: treeId,
        role: role,
        member_id: memberId ? Number(memberId) : null,
        permissions: { can_edit_members: false, can_view_stats: false, can_manage_media: false }
    };

    if (msg) { msg.textContent = 'Saving…'; msg.className = 'dh-msg'; }
    try {
        const res = await csrfFetch('users-api.php', {
            method: 'POST',
            body: JSON.stringify(payload)
        });
        const data = await res.json().catch(function () { return {}; });
        if (!res.ok || data.error) {
            if (msg) { msg.textContent = data.error || 'Could not add that access.'; msg.className = 'dh-msg bad'; }
            return;
        }
        if (msg) { msg.textContent = ''; msg.className = 'dh-msg'; }
        await fetchUsers();
        await editUser(USER_DETAIL.id);
    } catch (err) {
        console.error('add assignment', err);
        if (msg) { msg.textContent = 'Could not add that access.'; msg.className = 'dh-msg bad'; }
    }
}

async function removeAccess(userId, tenantId, treeName) {
    if (!confirm('Remove access to “' + treeName + '”? The account and their other trees stay.')) return;
    try {
        const res = await csrfFetch('users-api.php', {
            method: 'POST',
            body: JSON.stringify({ action: 'remove_access', user_id: userId, tenant_id: tenantId })
        });
        const data = await res.json().catch(function () { return {}; });
        if (!res.ok || data.error) { alert(data.error || 'Could not remove that access.'); return; }
        if (data.logged_out) { window.location.href = '/admin/'; return; }
        if (data.switched) { window.location.reload(); return; }
        await fetchUsers();
        await editUser(userId);
    } catch (err) {
        console.error('remove access', err);
        alert('Could not remove that access.');
    }
}

async function deleteAccount() {
    if (!USER_DETAIL) return;
    if (!confirm('Delete the account for “' + (USER_DETAIL.display_name || USER_DETAIL.email) + '”? This cannot be undone. Their tree access is removed; trees they do not solely own stay.')) return;
    try {
        const res = await csrfFetch('users-api.php?id=' + encodeURIComponent(USER_DETAIL.id), { method: 'DELETE' });
        const data = await res.json().catch(function () { return {}; });
        if (!res.ok || data.error) { alert(data.error || 'Could not delete the account.'); return; }
        closeUserDetail();
        await fetchUsers();
    } catch (err) {
        console.error('delete account', err);
        alert('Could not delete the account.');
    }
}

function closeUserDetail() {
    const wrap = document.getElementById('userDetailWrap');
    if (wrap) wrap.style.display = 'none';
    USER_DETAIL = null;
    // Show the "New account" form again when closing detail
    const createWrap = document.getElementById('userCreateWrap');
    if (createWrap) { createWrap.style.display = 'block'; createWrap.hidden = false; }
}

/* Listeners */
document.getElementById('userSearch')?.addEventListener('input', applyUserFilters);

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('#usersTable thead th').forEach(function (th) {
        th.addEventListener('click', function () {
            const col = th.getAttribute('data-ucol');
            if (!col) return;
            if (userSortState.column === col) userSortState.dir = userSortState.dir === 'asc' ? 'desc' : 'asc';
            else { userSortState.column = col; userSortState.dir = 'asc'; }
            renderUsersTable();
        });
    });
});
