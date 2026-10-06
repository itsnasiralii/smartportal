<?php if ($authenticated): ?>
<div id="tab-admin-handover" class="tab-content">
 <div class="panel-card"><div class="panel-header"><h2>Handover Settings</h2><p>Manage dropdown options and the overdue timer. Reload the Handover page after saving. Existing records keep their original values.</p></div>
 <div class="panel-body"><form method="post">
 <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
 <input type="hidden" name="action" value="save_handover_settings">
 <?php foreach (['vendors'=>'Vendors', 'teams'=>'Follow-up teams', 'regions'=>'Regions', 'comments'=>'Quick comments'] as $key=>$label): ?>
 <div class="form-group"><label for="hs-<?= $key ?>"><?= $label ?> — one option per line; add, rename or remove as needed</label><textarea id="hs-<?= $key ?>" name="<?= $key ?>" rows="5" maxlength="10000"><?= htmlspecialchars(implode("\n", $handoverConfig[$key])) ?></textarea></div>
 <?php endforeach; ?>
 <div class="form-group"><label for="hs-minutes">Pending complaint turns red after (minutes)</label><input id="hs-minutes" name="overdueMinutes" type="number" min="1" max="10080" required value="<?= (int)$handoverConfig['overdueMinutes'] ?>"></div>
 <button class="btn-primary">Save Handover Settings</button>
 </form><p>Complaint handler: CNOC. Edit individual handovers from the Handover tab in the browser where they were created.</p></div></div>
</div>
<?php endif; ?>
