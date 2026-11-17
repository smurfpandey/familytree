<?php
/**
 * admin/instance-trees.php - every family tree on this installation.
 * Included by admin/index.php inside the logged-in section.
 */
?>
<div class="page-title-bar">
    <div>
        <h2>Trees</h2>
        <p class="settings-desc" style="margin:2px 0 0;">
            All family trees on this instance. Open any tree you belong to, plant a new one,
            or hand a tree you own to another owner.
        </p>
    </div>
    <button class="btn btn-primary" type="button" onclick="openNewTreeDialog()">+ New tree</button>
</div>

<section class="table-wrap">
    <div class="table-toolbar">
        <div class="table-toolbar-left">
            <div>
                <strong>Family trees</strong>
                <span id="instanceTreeCountBadge" class="badge">0</span>
            </div>
            <span>Each tree has its own members, settings and data home</span>
        </div>
    </div>
    <div class="table-scroll">
        <table id="instanceTreesTable" class="data-table">
            <thead>
                <tr>
                    <th>Tree</th>
                    <th>Link</th>
                    <th>Visibility</th>
                    <th>Main owner</th>
                    <th>People with access</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</section>

<div class="ob-overlay" id="transferTreeOverlay" style="display:none">
    <div class="ob-card" role="dialog" aria-modal="true" aria-labelledby="transferTreeTitle">
        <div class="ob-head" style="margin-bottom:14px">
            <h2 id="transferTreeTitle">Make an owner</h2>
            <p id="transferTreeDesc">Choose an account to become an owner of this tree. The current owners stay owners.</p>
        </div>
        <div class="ob-field" style="margin-bottom:10px">
            <label for="transferTreeUser">Account</label>
            <select id="transferTreeUser"></select>
        </div>
        <div class="ob-hint" id="transferTreeMsg" style="min-height:18px"></div>
        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:10px">
            <button type="button" class="btn btn-ghost" onclick="closeTransferTreeDialog()">Cancel</button>
            <button type="button" class="btn btn-primary" id="transferTreeSubmit" onclick="submitTreeTransfer()">Make owner</button>
        </div>
    </div>
</div>