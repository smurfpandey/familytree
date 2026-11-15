<?php // admin/users.php - accounts on the whole instance, and tree access ?>
<div class="page-title-bar">
    <div>
        <h2>Users</h2>
        <p class="settings-desc" style="margin:2px 0 0;">
            Accounts belong to this whole instance. A person keeps one login and can be given
            access to any trees you own. Their role and member card can be different in each tree.
        </p>
    </div>
    <button class="btn btn-primary" type="button" onclick="focusNewAccount()">New account</button>
</div>

<section class="table-wrap">
    <div class="table-toolbar">
        <div class="table-toolbar-left">
            <div>
                <strong>Accounts</strong>
                <span id="userCountBadge" class="badge">0</span>
            </div>
            <span>Every account on this instance, including accounts with no tree yet</span>
        </div>
        <div class="table-toolbar-right">
            <input id="userSearch" type="text" class="input-chip" placeholder="Search name, email, tree">
        </div>
    </div>
    <div class="table-scroll">
        <table id="usersTable">
            <thead>
                <tr>
                    <th data-ucol="display_name">Name</th>
                    <th data-ucol="email">Email</th>
                    <th data-ucol="trees">Tree access</th>
                    <th data-ucol="last_login_at">Last login</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</section>

<!-- ── One account, with its tree access ───────────────────────── -->
<section class="form-wrap" id="userDetailWrap" style="display:none;margin-top:12px;">
    <div class="form-header">
        <div class="form-header-text">
            <h3 id="userDetailName">Account</h3>
            <small id="userDetailEmail"></small>
        </div>
    </div>
    <p class="form-note" id="userDetailNote"></p>

    <div class="form-section">
        <div class="section-title">Tree access</div>
        <div id="userAssignments" style="display:flex;flex-direction:column;gap:10px;"></div>
        <p class="dh-hint" id="userOtherAccess" style="margin:8px 0 0;"></p>
    </div>

    <div class="form-section">
        <div class="section-title">Give access to another tree</div>
        <div class="form-row">
            <div class="form-group">
                <label for="assignTree">Tree</label>
                <select id="assignTree" class="form-control" onchange="loadAssignMembers()"></select>
            </div>
            <div class="form-group">
                <label for="assignRole">Role</label>
                <select id="assignRole" class="form-control">
                    <option value="viewer">Viewer</option>
                    <option value="editor">Editor</option>
                    <option value="owner">Owner</option>
                </select>
            </div>
            <div class="form-group">
                <label for="assignMember">Linked member</label>
                <select id="assignMember" class="form-control">
                    <option value="">None</option>
                </select>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:12px;">
            <button class="btn btn-primary" type="button" onclick="addAssignment()">Add access</button>
            <span class="dh-msg" id="assignMsg"></span>
        </div>
    </div>

    <div style="display:flex;gap:8px;margin-top:16px;align-items:center;">
        <button class="btn btn-ghost" type="button" onclick="closeUserDetail()">Close</button>
        <button class="btn btn-danger" type="button" onclick="deleteAccount()" id="deleteAccountBtn" style="margin-left:auto;display:none;">Delete account</button>
    </div>
</section>

<!-- ── New account ─────────────────────────────────────────────── -->
<section class="form-wrap" id="userCreateWrap" style="margin-top:12px;">
    <div class="form-header">
        <div class="form-header-text">
            <h3>New account</h3>
            <small>One login. Give it tree access now or later.</small>
        </div>
    </div>
    <p class="form-note">The account owner changes their own name, email and password in Profile.</p>

    <form id="userCreateForm" onsubmit="createAccount(event)">
        <div class="form-section">
            <div class="section-title">Account</div>
            <div class="form-row">
                <div class="form-group">
                    <label for="newAccountName">Display name</label>
                    <input id="newAccountName" required autocomplete="off">
                </div>
                <div class="form-group">
                    <label for="newAccountEmail">Email</label>
                    <input id="newAccountEmail" type="email" required autocomplete="off">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="newAccountPassword">Password</label>
                    <input id="newAccountPassword" type="password" minlength="8" required autocomplete="new-password">
                    <span class="form-hint">At least 8 characters. They can change it after signing in.</span>
                </div>
            </div>
        </div>

        <div class="form-section" id="newAccountTreesSection">
            <div class="section-title">Tree access</div>
            <p class="dh-hint" style="margin:0 0 8px;">Only trees you own can be assigned here.</p>
            <div id="newAccountTrees" style="display:flex;flex-direction:column;gap:8px;"></div>
        </div>

        <div style="display:flex;gap:8px;margin-top:16px;align-items:center;">
            <button class="btn btn-primary" type="submit">Create account</button>
            <button class="btn btn-ghost" type="button" onclick="resetCreateAccount()">Clear</button>
            <span class="dh-msg" id="createAccountMsg"></span>
        </div>
    </form>
</section>
