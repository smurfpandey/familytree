<?php
/**
 * admin/instance-settings.php - settings for the whole installation.
 * Included by admin/index.php inside the logged-in section.
 */
?>
<div class="page-title-bar" style="margin-bottom:20px;">
    <h2>Instance Settings</h2>
</div>

<div class="settings-wrap">

    <section class="settings-card">
        <h2 class="settings-heading">General</h2>
        <p class="settings-desc">
            These settings apply to this whole installation, not to one family tree.
            Each tree keeps its own name, look and privacy in <strong>Tree Settings</strong>.
        </p>
        <div class="form-group" style="max-width:420px;">
            <label for="instanceName">Instance name</label>
            <input type="text" id="instanceName" class="form-control" maxlength="80" placeholder="FamilyTree">
            <span class="form-hint">Shown in the admin header and browser title.</span>
        </div>
    </section>

    <section class="settings-card">
        <h2 class="settings-heading">Default public tree</h2>
        <p class="settings-desc">
            The tree visitors see at the root address. A saved default is used while it is active;
            otherwise the oldest active tree is used.
        </p>
        <div class="form-group" style="max-width:460px;">
            <label for="instanceDefaultTree">Tree</label>
            <select id="instanceDefaultTree" class="form-control">
                <option value="">Oldest active tree</option>
            </select>
            <span class="form-hint" id="instanceDefaultTreeHint"></span>
        </div>
    </section>

    <section class="settings-card">
        <h2 class="settings-heading">New tree defaults</h2>
        <p class="settings-desc">
            Copied into each new tree when it is created. Changing these later never changes
            trees that already exist.
        </p>

        <div class="form-row">
            <div class="form-group">
                <label for="instanceNewVisibility">Visibility</label>
                <select id="instanceNewVisibility" class="form-control">
                    <option value="private">Private: members only</option>
                    <option value="unlisted">Unlisted: link only, kept out of search</option>
                    <option value="public">Public: anyone with the link</option>
                </select>
            </div>
            <div class="form-group">
                <label for="instanceNewTheme">Tree look</label>
                <select id="instanceNewTheme" class="form-control"></select>
            </div>
        </div>

        <label class="dh-check" style="margin:4px 0 18px;">
            <input type="checkbox" id="instanceNewHindu">
            Show Hindu dates (tithi) in new trees
        </label>

        <h3 class="settings-heading" style="font-size:14px;margin-top:4px;">Per-field privacy</h3>
        <p class="settings-desc" style="margin-bottom:10px;">
            The starting visibility of each field in a new tree. Living-person protection is
            always enforced on top of these choices.
        </p>
        <div class="privacy-table-wrap">
            <table class="privacy-table">
                <thead>
                    <tr>
                        <th>Field</th>
                        <th>Public</th>
                        <th>Members only</th>
                        <th>Private</th>
                    </tr>
                </thead>
                <tbody id="instancePrivacyBody"></tbody>
            </table>
        </div>
    </section>

    <div class="settings-actions">
        <button type="button" class="btn btn-primary" id="saveInstanceBtn" onclick="saveInstanceSettings()">
            Save Instance Settings
        </button>
        <span class="dh-msg" id="instanceSaveMsg"></span>
    </div>

</div>
