<?php
/** @var array $builder */
$bType          = $builder['type'] ?? 'template';
$bId            = (int) ($builder['id'] ?? 0);
$bRef           = (string) ($builder['ref'] ?? '');
$bName          = (string) ($builder['name'] ?? '');
$bSubject       = (string) ($builder['subject'] ?? '');
$bBlocks        = $builder['blocks'] ?? [];
$bIsActive      = (bool) ($builder['is_active'] ?? true);
$bBackUrl       = (string) ($builder['back_url'] ?? '/admin/emails/newsletters');
$bGallery       = $builder['gallery_images'] ?? [];
$bEcommerceMeta = $builder['ecommerce_meta'] ?? null;
$bRecipientMode = (string) ($builder['recipient_mode'] ?? 'client');
$bAdminRecip    = (string) ($builder['admin_recipients'] ?? '');
$bTokens        = $builder['available_tokens'] ?? [];
$bBlocksJson     = json_encode($bBlocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
$bShowStarter    = empty($bBlocks);
$bAppUrl         = rtrim((string) ((require __DIR__ . '/../../../config/app.php')['url'] ?? ''), '/');
$bNewsletterLists = $builder['newsletter_lists'] ?? [];
$bListId         = (int) ($builder['list_id'] ?? 0);
$bListIds        = $builder['list_ids'] ?? ($bListId > 0 ? [$bListId] : []);
$bCampaignStatus = (string) ($builder['status'] ?? 'draft');
$bScheduledAt    = !empty($builder['scheduled_at'])
    ? htmlspecialchars(date('Y-m-d\TH:i', strtotime((string) $builder['scheduled_at'])), ENT_QUOTES)
    : '';
?>
<style>
/* ── Email Builder ─────────────────────────────────────────── */
.eb-wrap{display:flex;flex-direction:column;height:calc(100vh - 52px);margin:-8px -16px -24px;overflow:hidden;}
/* Height is overridden by JS below to fill exactly the remaining viewport */
.eb-topbar{display:flex;align-items:center;gap:8px;padding:8px 14px;background:#fff;border-bottom:1px solid #e5e7eb;flex-shrink:0;flex-wrap:wrap;}
.eb-topbar .eb-back{color:#374151;text-decoration:none;font-size:13px;padding:5px 8px;border-radius:5px;}
.eb-topbar .eb-back:hover{background:#f3f4f6;}
.eb-title{flex:1;min-width:0;display:flex;flex-direction:column;gap:1px;}
.eb-title small{font-size:11px;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;}
.eb-title strong{font-size:14px;color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.eb-topbar-actions{display:flex;align-items:center;gap:6px;}
.eb-history-btns button{padding:5px 9px;font-size:13px;background:#fff;border:1px solid #d1d5db;border-radius:5px;cursor:pointer;color:#374151;}
.eb-history-btns button:disabled{opacity:.35;cursor:default;}
.eb-history-btns button:not(:disabled):hover{background:#f9fafb;}
.eb-preview-tabs{display:flex;border:1px solid #d1d5db;border-radius:6px;overflow:hidden;}
.eb-preview-tabs button{padding:5px 11px;font-size:12px;background:#fff;border:none;cursor:pointer;color:#6b7280;border-right:1px solid #d1d5db;}
.eb-preview-tabs button:last-child{border-right:none;}
.eb-preview-tabs button.active{background:#1e6b50;color:#fff;}
.eb-preview-tabs button:not(.active):hover{background:#f3f4f6;}
.eb-dirty-dot{width:7px;height:7px;border-radius:50%;background:#ef4444;display:none;}
.eb-dirty-dot.visible{display:inline-block;}

/* ── Body layout ────────────────────────────────────────── */
.eb-body{display:flex;flex:1;min-height:0;overflow:hidden;}

/* ── Left palette ───────────────────────────────────────── */
.eb-palette{width:190px;flex-shrink:0;background:#f9fafb;border-right:1px solid #e5e7eb;display:flex;flex-direction:column;overflow-y:auto;overscroll-behavior:contain;}
.eb-palette-section{padding:10px 8px 6px;}
.eb-palette-title{font-size:10px;font-weight:700;letter-spacing:.6px;color:#9ca3af;text-transform:uppercase;margin-bottom:5px;padding:0 4px;}
.eb-block-btn{display:flex;align-items:center;gap:7px;width:100%;padding:7px 8px;margin:1px 0;border:1px solid transparent;border-radius:6px;background:#fff;cursor:grab;font-size:12.5px;color:#374151;text-align:left;transition:background .15s,border-color .15s;}
.eb-block-btn:hover{background:#f0fdf4;border-color:#a7f3d0;}
.eb-block-btn .eb-block-icon{width:22px;height:22px;border-radius:4px;background:#e5e7eb;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;color:#374151;}
.eb-colix{display:flex;gap:2px;width:18px;height:12px;}
.eb-colix span{background:#6b7280;border-radius:1px;min-width:0;}
.eb-palette-divider{border:none;border-top:1px solid #e5e7eb;margin:6px 4px;}

/* ── Settings panel (bottom of left) ───────────────────── */
.eb-settings-panel{border-top:1px solid #e5e7eb;padding:10px 8px;flex-shrink:0;}
.eb-settings-panel label{font-size:11.5px;color:#374151;display:block;margin-bottom:2px;margin-top:8px;}
.eb-settings-panel label:first-of-type{margin-top:0;}
.eb-settings-panel input[type=text],.eb-settings-panel select,.eb-settings-panel textarea{width:100%;padding:5px 7px;border:1px solid #d1d5db;border-radius:5px;font-size:12px;color:#111827;background:#fff;}
.eb-settings-panel textarea{resize:vertical;min-height:54px;}
.eb-settings-panel input[type=checkbox]{margin-right:5px;}

/* ── Center canvas ──────────────────────────────────────── */
.eb-center{flex:1;min-width:0;display:flex;flex-direction:column;overflow:hidden;background:#f3f4f6;}
.eb-canvas-scroll{flex:1;overflow-y:auto;overscroll-behavior:contain;padding:16px;display:flex;justify-content:center;}
.eb-canvas-inner{width:600px;max-width:100%;}
.eb-canvas-inner.mobile-view{width:375px;}

/* ── Block cards ────────────────────────────────────────── */
.eb-block-card{background:#fff;border:2px solid transparent;border-radius:6px;margin:2px 0;cursor:pointer;transition:border-color .15s;position:relative;}
.eb-block-card:hover{border-color:#6ee7b7;}
.eb-block-card.selected{border-color:#1e6b50;}
.eb-block-card.dragging{opacity:.4;}
.eb-block-toolbar{display:flex;align-items:center;gap:4px;padding:0 6px;max-height:0;overflow:hidden;opacity:0;background:#fff;border-bottom:1px solid #e5e7eb;border-radius:4px 4px 0 0;transition:max-height .15s ease,opacity .15s ease,padding .15s ease;}
.eb-block-card:hover .eb-block-toolbar{max-height:36px;padding:4px 6px;opacity:1;}
.eb-block-drag-handle{cursor:grab;color:#9ca3af;font-size:14px;padding:1px 4px;flex-shrink:0;line-height:1;}
.eb-block-drag-handle:active{cursor:grabbing;}
.eb-block-type-tag{font-size:10px;color:#6b7280;flex:1;font-weight:600;text-transform:uppercase;letter-spacing:.4px;}
.eb-block-btns{display:flex;flex-direction:row;gap:2px;margin-left:auto;}
.eb-block-btns button{padding:2px 6px;font-size:11px;background:transparent;border:1px solid #e5e7eb;border-radius:4px;cursor:pointer;color:#6b7280;}
.eb-block-btns button:hover:not(:disabled){background:#f3f4f6;color:#111827;}
.eb-block-btns button:disabled{opacity:.3;cursor:default;}
.eb-block-btns button.danger:hover{background:#fef2f2;border-color:#fca5a5;color:#dc2626;}
.eb-block-preview{overflow:hidden;border-radius:0 0 4px 4px;}

/* ── Drop zones ─────────────────────────────────────────── */
.eb-drop-zone{height:6px;border-radius:3px;transition:height .15s,background .15s;margin:0;}
.eb-drop-zone.drag-over{height:24px;background:#d1fae5;border:2px dashed #34d399;}
.eb-canvas-inner.dragging-active .eb-drop-zone{height:28px;}

/* ── Animations ─────────────────────────────────────────── */
@keyframes ebMoveUp   {from{transform:translateY(10px);opacity:.6}to{transform:translateY(0);opacity:1}}
@keyframes ebMoveDown {from{transform:translateY(-10px);opacity:.6}to{transform:translateY(0);opacity:1}}
@keyframes ebRemove   {from{opacity:1;transform:scaleY(1);max-height:400px;margin:2px 0}to{opacity:0;transform:scaleY(.8);max-height:0;margin:0;padding:0}}
.eb-block-card.anim-up   {animation:ebMoveUp   .22s ease both;}
.eb-block-card.anim-down {animation:ebMoveDown .22s ease both;}
.eb-block-card.removing  {animation:ebRemove   .2s ease forwards;overflow:hidden;pointer-events:none;}
/* ── Column drop cells ─────────────────────────────────── */
.eb-col-cell{flex:1;min-height:56px;border:1px dashed #d1d5db;border-radius:4px;padding:3px;transition:background .15s,border-color .15s;}
.eb-col-cell.col-drag-over{background:#d1fae5;border-color:#34d399;border-style:dashed;}

/* ── Empty state ─────────────────────────────────────────── */
.eb-empty-state{padding:40px 20px;text-align:center;color:#9ca3af;font-size:13px;}
.eb-empty-state button{margin-top:10px;}

/* ── Preview pane ───────────────────────────────────────── */
.eb-preview-pane{flex:1;overflow:auto;overscroll-behavior:contain;padding:16px;display:flex;justify-content:center;align-items:flex-start;background:#f3f4f6;}
.eb-preview-frame{background:#fff;border:1px solid #d1d5db;border-radius:8px;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,.08);}
.eb-preview-frame.mobile-preview{border-radius:20px;box-shadow:0 8px 32px rgba(0,0,0,.18);}
.eb-preview-frame iframe{display:block;width:100%;min-height:500px;border:none;}
.eb-preview-loading{padding:48px;text-align:center;color:#6b7280;font-size:13px;}

/* ── Right properties ───────────────────────────────────── */
.eb-props{width:270px;flex-shrink:0;background:#fff;border-left:1px solid #e5e7eb;overflow-y:auto;overscroll-behavior:contain;display:flex;flex-direction:column;}
.eb-props-header{padding:10px 12px;border-bottom:1px solid #e5e7eb;font-size:12px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.5px;background:#f9fafb;flex-shrink:0;}
.eb-props-body{padding:12px;flex:1;}
.eb-props-group{margin-bottom:14px;}
.eb-props-group label{display:block;font-size:11.5px;color:#6b7280;margin-bottom:3px;font-weight:500;}
.eb-props-group input[type=text],
.eb-props-group input[type=number],
.eb-props-group input[type=url],
.eb-props-group select,
.eb-props-group textarea{width:100%;padding:6px 8px;border:1px solid #d1d5db;border-radius:5px;font-size:12.5px;color:#111827;background:#fff;box-sizing:border-box;}
.eb-props-group textarea{resize:vertical;min-height:70px;line-height:1.5;}
.eb-props-group input[type=range]{width:100%;accent-color:#1e6b50;}
.eb-props-empty{padding:24px 12px;text-align:center;color:#9ca3af;font-size:12.5px;line-height:1.6;}
.eb-color-row{display:flex;gap:6px;align-items:center;}
.eb-color-row input[type=color]{width:36px;height:32px;padding:2px;border:1px solid #d1d5db;border-radius:5px;cursor:pointer;flex-shrink:0;}
.eb-color-row input[type=text]{flex:1;}
.eb-align-row-wrap{display:flex;flex-direction:column;gap:6px;}
.eb-align-row{display:flex;gap:4px;}
.eb-align-row button{flex:1;padding:5px 3px;border:1px solid #d1d5db;border-radius:5px;background:#fff;cursor:pointer;color:#6b7280;display:flex;flex-direction:column;align-items:center;gap:1px;}
.eb-align-row button span:first-child{font-size:11px;font-weight:500;}
.eb-align-row button span:last-child{font-size:13px;line-height:1;}
.eb-align-row button.active{background:#1e6b50;border-color:#1e6b50;color:#fff;}
.eb-align-gear{flex:0 0 auto;padding:4px 7px!important;font-size:13px!important;background:#f9fafb!important;border:1px solid #d1d5db!important;border-radius:5px!important;cursor:pointer!important;color:#6b7280!important;}
.eb-align-gear:hover{background:#e5e7eb!important;color:#111!important;}
.eb-align-margins{padding:6px 8px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:5px;}
.eb-image-row{display:flex;gap:5px;}
.eb-image-row input{flex:1;}
.eb-image-row button{padding:5px 8px;font-size:11px;white-space:nowrap;}
.eb-rich-bar{display:flex;gap:4px;margin-bottom:4px;}
.eb-rich-btn{padding:3px 9px;border:1px solid #d1d5db;border-radius:4px;background:#fff;cursor:pointer;font-size:13px;color:#374151;}
.eb-rich-btn:hover{background:#f3f4f6;border-color:#9ca3af;}
.eb-social-links{display:flex;flex-direction:column;gap:6px;margin-bottom:6px;}
.eb-social-link-row{display:flex;gap:5px;align-items:center;}
.eb-social-link-row select{width:90px;flex-shrink:0;padding:4px 5px;font-size:11.5px;}
.eb-social-link-row input{flex:1;padding:4px 6px;font-size:12px;}
.eb-social-link-row button{padding:3px 6px;font-size:11px;flex-shrink:0;}
.eb-tokens-list{display:flex;flex-wrap:wrap;gap:4px;margin-top:4px;}
.eb-token-btn{padding:2px 6px;font-size:10.5px;background:#f0fdf4;border:1px solid #a7f3d0;border-radius:4px;cursor:pointer;color:#166534;font-family:monospace;}
.eb-token-btn:hover{background:#dcfce7;}
.eb-props-sep{border:none;border-top:1px solid #f3f4f6;margin:12px 0;}

/* ── Modals ─────────────────────────────────────────────── */
.eb-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;display:flex;align-items:center;justify-content:center;}
.eb-modal{background:#fff;border-radius:10px;box-shadow:0 8px 40px rgba(0,0,0,.18);max-height:90vh;display:flex;flex-direction:column;overflow:hidden;}
.eb-modal-header{padding:14px 18px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.eb-modal-header h3{margin:0;font-size:15px;}
.eb-modal-close{background:none;border:none;font-size:18px;cursor:pointer;color:#9ca3af;line-height:1;}
.eb-modal-body{padding:16px 18px;overflow-y:auto;flex:1;}
.eb-starter-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;}
.eb-starter-card{border:2px solid #e5e7eb;border-radius:8px;padding:12px;cursor:pointer;text-align:center;transition:border-color .15s,background .15s;}
.eb-starter-card:hover{border-color:#1e6b50;background:#f0fdf4;}
.eb-starter-card .eb-starter-icon{font-size:28px;margin-bottom:6px;}
.eb-starter-card h4{margin:0 0 3px;font-size:12.5px;}
.eb-starter-card p{margin:0;font-size:11px;color:#9ca3af;}
.eb-gallery-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;}
.eb-gallery-img{border:2px solid #e5e7eb;border-radius:5px;overflow:hidden;cursor:pointer;aspect-ratio:1;transition:border-color .15s;}
.eb-gallery-img:hover{border-color:#1e6b50;}
.eb-gallery-img img{width:100%;height:100%;object-fit:cover;display:block;}
.eb-modal-url-input{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;margin-top:8px;box-sizing:border-box;}

/* ── Campaign Wizard ─────────────────────────────────────── */
.eb-wizard-bar{display:flex;align-items:center;gap:0;background:#fff;border-bottom:1px solid #e5e7eb;flex-shrink:0;padding:0 14px;overflow:hidden;}
.eb-wizard-step{display:flex;align-items:center;gap:7px;padding:10px 12px;font-size:13px;font-weight:500;color:#6b7280;cursor:pointer;border-bottom:2px solid transparent;white-space:nowrap;flex-shrink:0;transition:color .15s,border-color .15s;background:none;border-left:none;border-right:none;border-top:none;}
.eb-wizard-step:hover{color:#111827;}
.eb-wizard-step.active{color:#1e6b50;border-bottom-color:#1e6b50;}
.eb-wizard-step-num{width:20px;height:20px;border-radius:50%;background:#e5e7eb;color:#374151;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.eb-wizard-step.active .eb-wizard-step-num{background:#1e6b50;color:#fff;}
.eb-wizard-step.done .eb-wizard-step-num{background:#d1fae5;color:#065f46;}
.eb-wizard-step-sep{color:#d1d5db;font-size:12px;padding:0 4px;pointer-events:none;}
.eb-wizard-panel{flex:1;overflow-y:auto;padding:28px 32px;background:#f9fafb;}
.eb-wiz-section{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:24px;max-width:640px;margin:0 auto;}
.eb-wiz-section h3{margin:0 0 16px;font-size:15px;color:#111827;}
.eb-wiz-field{margin-bottom:16px;}
.eb-wiz-field label{display:block;font-size:12.5px;font-weight:600;color:#374151;margin-bottom:5px;}
.eb-wiz-field input,.eb-wiz-field select,.eb-wiz-field textarea{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;color:#111827;background:#fff;box-sizing:border-box;}
.eb-wiz-field input:focus,.eb-wiz-field select:focus,.eb-wiz-field textarea:focus{outline:none;border-color:#1e6b50;box-shadow:0 0 0 2px rgba(30,107,80,.1);}
.eb-wiz-field small{display:block;font-size:11px;color:#9ca3af;margin-top:3px;}
.eb-wiz-actions{display:flex;gap:10px;align-items:center;justify-content:flex-end;margin-top:20px;flex-wrap:wrap;}
.eb-wiz-actions .btn{padding:8px 20px;}
.eb-wiz-list-card{border:2px solid #e5e7eb;border-radius:8px;padding:14px 16px;cursor:pointer;margin-bottom:8px;display:flex;align-items:center;gap:12px;transition:border-color .15s,background .15s;}
.eb-wiz-list-card:hover{border-color:#a7f3d0;background:#f0fdf4;}
.eb-wiz-list-card.selected{border-color:#1e6b50;background:#f0fdf4;}
.eb-wiz-list-card .eb-wiz-list-name{font-weight:600;font-size:13.5px;color:#111827;}
.eb-wiz-list-card .eb-wiz-list-count{font-size:12px;color:#6b7280;}
.eb-wiz-list-card .eb-wiz-list-check{margin-left:auto;font-size:18px;color:#1e6b50;display:none;}
.eb-wiz-list-card.selected .eb-wiz-list-check{display:block;}
.eb-wiz-preview-wrap{display:flex;flex-direction:column;gap:16px;max-width:660px;margin:0 auto;}
.eb-wiz-preview-frame{background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;}
.eb-wiz-preview-tabs{display:flex;gap:8px;justify-content:center;}
.eb-wiz-preview-tabs button{padding:6px 18px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;background:#fff;cursor:pointer;color:#6b7280;}
.eb-wiz-preview-tabs button.active{background:#1e6b50;color:#fff;border-color:#1e6b50;}
.eb-wiz-send-box{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:20px;}
.eb-wiz-send-box h4{margin:0 0 14px;font-size:14px;color:#111827;}
.eb-wiz-send-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px;}
.eb-wiz-send-row:last-child{margin-bottom:0;}
.eb-wiz-send-row input[type=email],.eb-wiz-send-row input[type=datetime-local]{padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;flex:1;min-width:0;}
.status-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:12px;font-size:11.5px;font-weight:600;}
.status-badge.draft{background:#f3f4f6;color:#374151;}
.status-badge.scheduled{background:#fef9c3;color:#854d0e;}
.status-badge.sent{background:#d1fae5;color:#065f46;}
</style>

<div class="eb-wrap" id="eb-wrap">

  <!-- ── Top Bar ── -->
  <div class="eb-topbar">
    <a href="<?= htmlspecialchars($bBackUrl, ENT_QUOTES) ?>" class="eb-back" id="eb-back-link">← Înapoi</a>
    <div class="eb-title">
      <small><?= $bType === 'ecommerce' ? 'Email ecommerce' : ($bType === 'campaign' ? 'Campanie' : 'Template') ?></small>
      <strong id="eb-title-display"><?= htmlspecialchars($bName, ENT_QUOTES) ?></strong>
    </div>
    <div class="eb-topbar-actions">
      <span class="eb-dirty-dot" id="eb-dirty-dot" title="Modificări nesalvate"></span>
      <div class="eb-history-btns">
        <button id="btn-undo" disabled title="Anulează (Ctrl+Z)">↩ Undo</button>
        <button id="btn-redo" disabled title="Refă (Ctrl+Y)">↪ Redo</button>
      </div>
      <div class="eb-preview-tabs">
        <button data-preview="edit" class="active">✏ Editare</button>
        <button data-preview="desktop">🖥 Desktop</button>
        <button data-preview="mobile">📱 Mobil</button>
      </div>
      <button type="button" id="btn-save" class="btn" style="padding:6px 16px;">Salvează</button>
    </div>
  </div>

  <!-- ── Hidden save form ── -->
  <form id="eb-form" method="post" action="/admin/emails/builder" style="display:none;">
    <input type="hidden" name="builder_type"        value="<?= htmlspecialchars($bType, ENT_QUOTES) ?>">
    <input type="hidden" name="builder_id"          value="<?= $bId ?>">
    <input type="hidden" name="builder_ref"         value="<?= htmlspecialchars($bRef, ENT_QUOTES) ?>">
    <input type="hidden" name="builder_blocks_json" id="eb-blocks-input" value="<?= htmlspecialchars($bBlocksJson, ENT_QUOTES) ?>">
    <input type="hidden" name="builder_name"        id="eb-name-input"   value="<?= htmlspecialchars($bName, ENT_QUOTES) ?>">
    <input type="hidden" name="builder_subject"     id="eb-subject-input" value="<?= htmlspecialchars($bSubject, ENT_QUOTES) ?>">
    <?php if ($bIsActive): ?><input type="hidden" name="builder_is_active" id="eb-active-input" value="1"><?php endif; ?>
    <?php if ($bType === 'ecommerce'): ?>
    <input type="hidden" name="builder_recipient_mode"    id="eb-recip-mode-input" value="<?= htmlspecialchars($bRecipientMode, ENT_QUOTES) ?>">
    <input type="hidden" name="builder_admin_recipients"  id="eb-recip-admin-input" value="<?= htmlspecialchars($bAdminRecip, ENT_QUOTES) ?>">
    <?php endif; ?>
    <?php if ($bType === 'campaign'): ?>
    <input type="hidden" name="builder_list_ids" id="eb-list-ids-input" value="<?= htmlspecialchars(implode(',', $bListIds), ENT_QUOTES) ?>">
    <?php endif; ?>
  </form>

  <?php if ($bType === 'campaign'): ?>
  <!-- ── Campaign Wizard Bar ── -->
  <div class="eb-wizard-bar" id="eb-wizard-bar">
    <button type="button" class="eb-wizard-step active" data-wiz-step="1" onclick="ebWizGo(1)">
      <span class="eb-wizard-step-num">1</span>Conținut
    </button>
    <span class="eb-wizard-step-sep">›</span>
    <button type="button" class="eb-wizard-step" data-wiz-step="2" onclick="ebWizGo(2)">
      <span class="eb-wizard-step-num">2</span>Destinatari
    </button>
    <span class="eb-wizard-step-sep">›</span>
    <button type="button" class="eb-wizard-step" data-wiz-step="3" onclick="ebWizGo(3)">
      <span class="eb-wizard-step-num">3</span>Setări
    </button>
    <span class="eb-wizard-step-sep">›</span>
    <button type="button" class="eb-wizard-step" data-wiz-step="4" onclick="ebWizGo(4)">
      <span class="eb-wizard-step-num">4</span>Trimitere
    </button>
    <span style="margin-left:auto;padding:0 8px;">
      <span class="status-badge <?= htmlspecialchars($bCampaignStatus, ENT_QUOTES) ?>" id="eb-campaign-status-badge"><?= htmlspecialchars($bCampaignStatus, ENT_QUOTES) ?></span>
    </span>
  </div>
  <?php endif; ?>

  <!-- ── Body ── -->
  <div class="eb-body">

    <!-- ── Left Palette ── -->
    <aside class="eb-palette">
      <div class="eb-palette-section">
        <div class="eb-palette-title">Blocuri</div>
        <?php
        $palBlocks = [
          ['header','H','Header'],
          ['text','T','Text'],
          ['image','🖼','Imagine'],
          ['button','◉','Buton'],
          ['quote','❝','Citat'],
          ['social','◎','Social media'],
          ['divider','—','Separator'],
          ['spacer','↕','Spațiu'],
        ];
        foreach ($palBlocks as [$bt, $icon, $label]):
        ?>
        <button type="button" class="eb-block-btn" draggable="true" data-block-type="<?= $bt ?>">
          <span class="eb-block-icon"><?= $icon ?></span><?= $label ?>
        </button>
        <?php endforeach; ?>
      </div>
      <hr class="eb-palette-divider">
      <div class="eb-palette-section">
        <div class="eb-palette-title">Secțiuni</div>
        <button type="button" class="eb-block-btn" draggable="true" data-block-type="columns_2">
          <span class="eb-block-icon"><span class="eb-colix"><span style="flex:1"></span><span style="flex:1"></span></span></span>50% / 50%
        </button>
        <button type="button" class="eb-block-btn" draggable="true" data-block-type="columns_2_6040">
          <span class="eb-block-icon"><span class="eb-colix"><span style="flex:3"></span><span style="flex:2"></span></span></span>60% / 40%
        </button>
        <button type="button" class="eb-block-btn" draggable="true" data-block-type="columns_2_4060">
          <span class="eb-block-icon"><span class="eb-colix"><span style="flex:2"></span><span style="flex:3"></span></span></span>40% / 60%
        </button>
        <button type="button" class="eb-block-btn" draggable="true" data-block-type="columns_2_7030">
          <span class="eb-block-icon"><span class="eb-colix"><span style="flex:7"></span><span style="flex:3"></span></span></span>70% / 30%
        </button>
        <button type="button" class="eb-block-btn" draggable="true" data-block-type="columns_2_3070">
          <span class="eb-block-icon"><span class="eb-colix"><span style="flex:3"></span><span style="flex:7"></span></span></span>30% / 70%
        </button>
        <button type="button" class="eb-block-btn" draggable="true" data-block-type="columns_3">
          <span class="eb-block-icon"><span class="eb-colix"><span style="flex:1"></span><span style="flex:1"></span><span style="flex:1"></span></span></span>33% × 3
        </button>
      </div>
      <?php if ($bType !== 'campaign'): ?>
      <hr class="eb-palette-divider">
      <div class="eb-settings-panel">
        <div class="eb-palette-title">Setări template</div>
        <?php if ($bType !== 'ecommerce'): ?>
        <label>Nume template</label>
        <input type="text" id="eb-set-name" value="<?= htmlspecialchars($bName, ENT_QUOTES) ?>">
        <?php endif; ?>
        <label>Subiect email</label>
        <input type="text" id="eb-set-subject" value="<?= htmlspecialchars($bSubject, ENT_QUOTES) ?>">
        <?php if ($bType === 'ecommerce' && $bEcommerceMeta !== null): ?>
        <label>Destinatari</label>
        <select id="eb-set-recip-mode">
          <option value="client"       <?= $bRecipientMode === 'client' ? 'selected' : '' ?>>Doar client</option>
          <option value="admin"        <?= $bRecipientMode === 'admin' ? 'selected' : '' ?>>Doar admin</option>
          <option value="client_admin" <?= $bRecipientMode === 'client_admin' ? 'selected' : '' ?>>Client + admin</option>
        </select>
        <div id="eb-admin-recip-wrap" <?= in_array($bRecipientMode, ['admin','client_admin']) ? '' : 'style="display:none"' ?>>
          <label>Email-uri admin (separate prin virgulă)</label>
          <input type="text" id="eb-set-admin-recip" value="<?= htmlspecialchars($bAdminRecip, ENT_QUOTES) ?>">
        </div>
        <?php endif; ?>
        <?php if (!empty($bTokens)): ?>
        <label style="margin-top:10px;">Token-uri disponibile</label>
        <div class="eb-tokens-list" id="eb-tokens-list">
          <?php foreach ($bTokens as $tok): ?>
          <button type="button" class="eb-token-btn" data-token="<?= htmlspecialchars($tok, ENT_QUOTES) ?>" title="Click pentru a copia"><?= htmlspecialchars($tok, ENT_QUOTES) ?></button>
          <?php endforeach; ?>
        </div>
        <div style="font-size:10px;color:#9ca3af;margin-top:3px;">Click pe un token pentru a-l copia în clipboard</div>
        <?php endif; ?>
        <label style="margin-top:10px;display:flex;align-items:center;gap:6px;cursor:pointer;">
          <input type="checkbox" id="eb-set-active" <?= $bIsActive ? 'checked' : '' ?>> Template activ
        </label>
      </div>
      <?php endif; ?>
      <?php if ($bShowStarter): ?>
      <div class="eb-palette-section">
        <button type="button" class="btn btn-secondary" style="width:100%;font-size:12px;" onclick="ebShowStarterModal()">✦ Template de start</button>
      </div>
      <?php endif; ?>
    </aside>

    <!-- ── Center ── -->
    <div class="eb-center">
      <!-- Edit canvas -->
      <div class="eb-canvas-scroll" id="eb-canvas-scroll">
        <div class="eb-canvas-inner" id="eb-canvas-inner">
          <div id="eb-canvas"></div>
        </div>
      </div>
      <!-- Preview pane (hidden by default) -->
      <div class="eb-preview-pane" id="eb-preview-pane" style="display:none;">
        <div class="eb-preview-frame" id="eb-preview-frame">
          <div class="eb-preview-loading" id="eb-preview-loading">Se generează previzualizarea...</div>
          <iframe id="eb-preview-iframe" title="Preview email" style="display:none;"></iframe>
        </div>
      </div>
    </div>

    <!-- ── Right Props ── -->
    <aside class="eb-props">
      <div class="eb-props-header">Proprietăți bloc</div>
      <div class="eb-props-body" id="eb-props-body">
        <div class="eb-props-empty">Selectează un bloc din canvas pentru a-i edita proprietățile.</div>
      </div>
    </aside>

  </div><!-- /eb-body -->

  <?php if ($bType === 'campaign'): ?>
  <!-- ── Wizard Step 2: Destinatari ── -->
  <div class="eb-wizard-panel" id="eb-wiz-panel-2" style="display:none;">
    <div class="eb-wiz-section">
      <h3>Alege listele de abonați</h3>
      <p style="font-size:12.5px;color:#6b7280;margin:0 0 14px;">Poți selecta una sau mai multe liste. Abonații duplicați vor primi emailul o singură dată.</p>
      <?php if (empty($bNewsletterLists)): ?>
        <p style="color:#6b7280;font-size:13px;">Nu există liste de abonați. <a href="/admin/emails/newsletters?tab=subscribers">Creează o listă</a> mai întâi.</p>
      <?php else: ?>
        <div id="eb-wiz-list-count-summary" style="font-size:12px;color:#1e6b50;font-weight:600;margin-bottom:10px;min-height:18px;"></div>
        <?php foreach ($bNewsletterLists as $list): ?>
          <?php $lid = (int) ($list['id'] ?? 0); $checked = in_array($lid, $bListIds, true); ?>
          <div class="eb-wiz-list-card <?= $checked ? 'selected' : '' ?>"
               data-list-id="<?= $lid ?>" data-count="<?= (int) ($list['subscribers_count'] ?? 0) ?>"
               onclick="ebWizToggleList(<?= $lid ?>)">
            <div>
              <div class="eb-wiz-list-name"><?= htmlspecialchars((string) ($list['name'] ?? ''), ENT_QUOTES) ?></div>
              <div class="eb-wiz-list-count"><?= (int) ($list['subscribers_count'] ?? 0) ?> abonați activi</div>
            </div>
            <span class="eb-wiz-list-check">✓</span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
      <div class="eb-wiz-actions">
        <button type="button" class="btn btn-secondary" onclick="ebWizGo(1)">← Înapoi</button>
        <button type="button" class="btn" onclick="ebWizSaveAndGo(3)">Continuă →</button>
      </div>
    </div>
  </div>

  <!-- ── Wizard Step 3: Setări ── -->
  <div class="eb-wizard-panel" id="eb-wiz-panel-3" style="display:none;">
    <div class="eb-wiz-section">
      <h3>Setările campaniei</h3>
      <div class="eb-wiz-field">
        <label>Numele campaniei</label>
        <input type="text" id="eb-wiz-name" value="<?= htmlspecialchars($bName, ENT_QUOTES) ?>" oninput="document.getElementById('eb-name-input').value=this.value;document.getElementById('eb-title-display').textContent=this.value;markDirty()">
      </div>
      <div class="eb-wiz-field">
        <label>Subiect email</label>
        <input type="text" id="eb-wiz-subject" value="<?= htmlspecialchars($bSubject, ENT_QUOTES) ?>" oninput="document.getElementById('eb-subject-input').value=this.value;markDirty()">
        <small>Acesta este subiectul pe care abonații îl vor vedea în inbox.</small>
      </div>
      <div class="eb-wiz-actions">
        <button type="button" class="btn btn-secondary" onclick="ebWizGo(2)">← Înapoi</button>
        <button type="button" class="btn" onclick="ebWizSaveAndGo(4)">Continuă →</button>
      </div>
    </div>
  </div>

  <!-- ── Wizard Step 4: Trimitere ── -->
  <div class="eb-wizard-panel" id="eb-wiz-panel-4" style="display:none;">
    <div class="eb-wiz-preview-wrap">
      <div class="eb-wiz-send-box">
        <h4>Previzualizare</h4>
        <div class="eb-wiz-preview-tabs">
          <button type="button" class="active" id="fpwiz-desktop" onclick="ebWizPreviewWidth(600)">🖥 Desktop</button>
          <button type="button" id="fpwiz-mobile" onclick="ebWizPreviewWidth(375)">📱 Mobil</button>
        </div>
        <div style="margin-top:12px;border:1px solid #e5e7eb;border-radius:8px;overflow:auto;max-height:420px;background:#fff;">
          <div id="eb-wiz-prev-loading" style="padding:32px;text-align:center;color:#9ca3af;font-size:13px;">Se generează previzualizarea...</div>
          <iframe id="eb-wiz-prev-iframe" title="Preview newsletter" style="display:none;width:600px;max-width:100%;border:none;min-height:300px;"></iframe>
        </div>
      </div>

      <div class="eb-wiz-send-box">
        <h4>Trimite test</h4>
        <form method="post" action="/admin/emails" onsubmit="return ebWizSyncBeforeAction()">
          <input type="hidden" name="section" value="newsletter-campaign-send-test">
          <input type="hidden" name="campaign_id" value="<?= $bId ?>">
          <input type="hidden" name="from_builder" value="1">
          <div class="eb-wiz-send-row">
            <input type="email" name="test_email" placeholder="adresa@email.com" required>
            <button class="btn btn-secondary" type="submit">Trimite test</button>
          </div>
        </form>
      </div>

      <div class="eb-wiz-send-box">
        <h4>Acțiuni</h4>
        <div class="eb-wiz-send-row">
          <form method="post" action="/admin/emails" onsubmit="return ebWizSyncBeforeAction()">
            <input type="hidden" name="section" value="newsletter-campaign-send-now">
            <input type="hidden" name="campaign_id" value="<?= $bId ?>">
            <input type="hidden" name="from_builder" value="1">
            <button class="btn" type="submit" onclick="return confirm('Trimiți newsletter-ul acum către toți abonații?')">🚀 Trimite acum</button>
          </form>
          <form method="post" action="/admin/emails" style="display:flex;gap:8px;flex:1;min-width:0;" onsubmit="return ebWizSyncBeforeAction()">
            <input type="hidden" name="section" value="newsletter-campaign-schedule">
            <input type="hidden" name="campaign_id" value="<?= $bId ?>">
            <input type="hidden" name="from_builder" value="1">
            <input type="datetime-local" name="scheduled_at" value="<?= $bScheduledAt ?>" required style="flex:1;min-width:0;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
            <button class="btn btn-secondary" type="submit">📅 Programează</button>
          </form>
        </div>
        <div class="eb-wiz-send-row" style="margin-top:8px;">
          <form method="post" action="/admin/emails/builder" onsubmit="sync();clearDirty();" style="display:flex;gap:8px;">
            <input type="hidden" name="builder_type" value="campaign">
            <input type="hidden" name="builder_id" value="<?= $bId ?>">
            <input type="hidden" id="eb-wiz-save-draft-blocks" name="builder_blocks_json" value="">
            <input type="hidden" id="eb-wiz-save-draft-name" name="builder_name" value="<?= htmlspecialchars($bName, ENT_QUOTES) ?>">
            <input type="hidden" id="eb-wiz-save-draft-subject" name="builder_subject" value="<?= htmlspecialchars($bSubject, ENT_QUOTES) ?>">
            <input type="hidden" id="eb-wiz-save-draft-list" name="builder_list_ids" value="<?= htmlspecialchars(implode(',', $bListIds), ENT_QUOTES) ?>">
            <button class="btn btn-secondary" type="submit" onclick="ebWizSyncDraftForm()">💾 Salvează draft</button>
          </form>
        </div>
      </div>

      <div style="text-align:center;">
        <button type="button" class="btn btn-secondary" onclick="ebWizGo(3)" style="font-size:12px;">← Înapoi la setări</button>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /eb-wrap -->

<!-- ── Starter Templates Modal ── -->
<div class="eb-modal-overlay" id="eb-starter-modal" style="display:none;" onclick="if(event.target===this)ebCloseStarterModal()">
  <div class="eb-modal" style="width:560px;">
    <div class="eb-modal-header">
      <h3>Alege un template de start</h3>
      <button type="button" class="eb-modal-close" onclick="ebCloseStarterModal()">✕</button>
    </div>
    <div class="eb-modal-body">
      <div class="eb-starter-grid">
        <div class="eb-starter-card" onclick="ebApplyStarter('blank')">
          <div class="eb-starter-icon">📄</div>
          <h4>Blank</h4>
          <p>Pornești de la zero</p>
        </div>
        <div class="eb-starter-card" onclick="ebApplyStarter('welcome')">
          <div class="eb-starter-icon">👋</div>
          <h4>Bun venit</h4>
          <p>Header + text + buton</p>
        </div>
        <div class="eb-starter-card" onclick="ebApplyStarter('promo')">
          <div class="eb-starter-icon">🎁</div>
          <h4>Promoție</h4>
          <p>Header + imagine + text + buton</p>
        </div>
        <div class="eb-starter-card" onclick="ebApplyStarter('newsletter')">
          <div class="eb-starter-icon">📰</div>
          <h4>Newsletter</h4>
          <p>Structură completă de newsletter</p>
        </div>
        <div class="eb-starter-card" onclick="ebApplyStarter('order')">
          <div class="eb-starter-icon">📦</div>
          <h4>Email comandă</h4>
          <p>Template pentru notificări comandă</p>
        </div>
        <div class="eb-starter-card" onclick="ebApplyStarter('simple')">
          <div class="eb-starter-icon">✉</div>
          <h4>Simplu</h4>
          <p>Header + text + separator + text</p>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── Full Preview Modal ── -->
<div class="eb-modal-overlay" id="eb-fullpreview-modal" style="display:none;">
  <div class="eb-modal" style="width:96vw;max-width:1100px;height:92vh;">
    <div class="eb-modal-header">
      <h3>Preview email</h3>
      <div style="display:flex;gap:8px;align-items:center;">
        <div class="eb-preview-tabs" style="margin:0;">
          <button type="button" id="fprev-desktop" class="active" onclick="ebFullPreviewSetWidth(960)">🖥 Desktop</button>
          <button type="button" id="fprev-mobile" onclick="ebFullPreviewSetWidth(375)">📱 Mobil</button>
        </div>
        <button type="button" class="eb-modal-close" onclick="ebCloseFullPreview()">✕</button>
      </div>
    </div>
    <div class="eb-modal-body" style="padding:0;overflow:auto;display:flex;justify-content:center;background:#f3f4f6;">
      <div id="eb-fprev-wrap" style="padding:16px;width:100%;display:flex;justify-content:center;">
        <div id="eb-fprev-loading" style="padding:60px;color:#6b7280;font-size:13px;">Se generează previzualizarea...</div>
        <iframe id="eb-fprev-iframe" title="Full preview" style="display:none;border:1px solid #d1d5db;border-radius:8px;box-shadow:0 4px 20px rgba(0,0,0,.1);background:#fff;" frameborder="0"></iframe>
      </div>
    </div>
  </div>
</div>

<!-- ── Image Picker Modal ── -->
<div class="eb-modal-overlay" id="eb-img-modal" style="display:none;">
  <div class="eb-modal" style="width:580px;">
    <div class="eb-modal-header">
      <h3>Alege imagine</h3>
      <button type="button" class="eb-modal-close" onclick="ebCloseImgModal()">✕</button>
    </div>
    <div class="eb-modal-body">
      <input type="text" class="eb-modal-url-input" id="eb-img-url-input" placeholder="Sau lipește URL imagine direct...">
      <div style="margin:10px 0;font-size:12px;color:#9ca3af;text-align:center;">sau alege din galerie</div>
      <input type="text" id="eb-gallery-search" placeholder="🔍 Caută imagini după titlu..." style="width:100%;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12.5px;margin-bottom:8px;box-sizing:border-box;">
      <div class="eb-gallery-grid" id="eb-gallery-grid">
        <?php foreach ($bGallery as $img): ?>
        <div class="eb-gallery-img" data-url="<?= htmlspecialchars((string)($img['image_url']??''), ENT_QUOTES) ?>" onclick="ebPickGalleryImg(this.dataset.url)" title="<?= htmlspecialchars((string)($img['alt_text']??$img['title']??''), ENT_QUOTES) ?>">
          <img src="<?= htmlspecialchars((string)($img['image_url']??''), ENT_QUOTES) ?>" alt="<?= htmlspecialchars((string)($img['alt_text']??''), ENT_QUOTES) ?>" loading="lazy">
        </div>
        <?php endforeach; ?>
        <?php if (empty($bGallery)): ?>
        <p style="grid-column:1/-1;text-align:center;color:#9ca3af;padding:20px;">Nicio imagine în galerie. Adaugă imagini din secțiunea Galerie.</p>
        <?php endif; ?>
      </div>
      <div id="eb-gallery-pager" style="display:none;justify-content:center;gap:12px;margin-top:10px;align-items:center;"></div>
      <div style="margin-top:12px;text-align:right;">
        <button type="button" class="btn btn-secondary" onclick="ebCloseImgModal()">Anulează</button>
        <button type="button" class="btn" onclick="ebConfirmImgUrl()">Folosește URL</button>
      </div>
    </div>
  </div>
</div>

<script>
(function() {
'use strict';

/* ═══════════════════════════════════════════════════════════
   STATE
═══════════════════════════════════════════════════════════ */
let blocks  = <?= $bBlocksJson ?>;
let sel     = -1;       // selected block index
let history = [];
let future  = [];
let dirty   = false;
let previewMode = 'edit';
let dragState   = null;
let imgPickCallback = null;

/* ═══════════════════════════════════════════════════════════
   BLOCK DEFAULTS
═══════════════════════════════════════════════════════════ */
const BLOCK_TYPES = {
  header:         {label:'Header',      icon:'H'},
  text:           {label:'Text',        icon:'T'},
  image:          {label:'Imagine',     icon:'🖼'},
  button:         {label:'Buton',       icon:'◉'},
  quote:          {label:'Citat',       icon:'❝'},
  social:         {label:'Social',      icon:'◎'},
  divider:        {label:'Separator',   icon:'—'},
  spacer:         {label:'Spațiu',      icon:'↕'},
  columns_2:      {label:'50% / 50%',   icon:'▌▌'},
  columns_2_6040: {label:'60% / 40%',   icon:'▐▌'},
  columns_2_4060: {label:'40% / 60%',   icon:'▌▐'},
  columns_2_7030: {label:'70% / 30%',   icon:'▐▏'},
  columns_2_3070: {label:'30% / 70%',   icon:'▏▐'},
  columns_3:      {label:'33%×3',       icon:'▌▌▌'},
};
// Returns column widths (%) for a given type
const colWidths = type => {
  if(type==='columns_3')      return [33,34,33];
  if(type==='columns_2_6040') return [60,40];
  if(type==='columns_2_4060') return [40,60];
  if(type==='columns_2_7030') return [70,30];
  if(type==='columns_2_3070') return [30,70];
  return [50,50];
};
const isColumns2 = type => type==='columns_2'||type==='columns_2_6040'||type==='columns_2_4060'||type==='columns_2_7030'||type==='columns_2_3070';

function blockDefault(type) {
  const d = {
    header:    {type:'header',    content:'Titlul tău',            align:'center', font_size:32, font_size_mobile:24, background:'#2d7d5f', text_color:'#ffffff'},
    text:      {type:'text',      content:'Scrie textul tău aici...', align:'left', font_size:16, font_size_mobile:14, background:'#ffffff', text_color:'#333333'},
    image:     {type:'image',     image_url:'',                    alt:'',         align:'center', background:'#ffffff', width:100, link_url:''},
    button:    {type:'button',    label:'Apasă aici',              url:'',         align:'center', background:'#ffffff', button_color:'#2d7d5f', text_color:'#ffffff', border_radius:6},
    quote:     {type:'quote',     content:'Scrie citatul tău...',  author:'',      align:'left',   background:'#f0fdf4', text_color:'#166534', border_color:'#2d7d5f', font_size:16},
    social:    {type:'social',    links:[{platform:'facebook',url:''},{platform:'instagram',url:''}], align:'center', background:'#ffffff', icon_size:32},
    divider:   {type:'divider',   background:'#ffffff',            color:'#e5e7eb', width:80, thickness:1},
    spacer:    {type:'spacer',    height:24,                       background:'#ffffff'},
    columns_2:      {type:'columns_2',      background:'#ffffff', gap:16, columns:[[],[]]},
    columns_2_6040: {type:'columns_2_6040', background:'#ffffff', gap:16, columns:[[],[]]},
    columns_2_4060: {type:'columns_2_4060', background:'#ffffff', gap:16, columns:[[],[]]},
    columns_2_7030: {type:'columns_2_7030', background:'#ffffff', gap:16, columns:[[],[]]},
    columns_2_3070: {type:'columns_2_3070', background:'#ffffff', gap:16, columns:[[],[]]},
    columns_3:      {type:'columns_3',      background:'#ffffff', gap:12, columns:[[],[],[]]},
  };
  return JSON.parse(JSON.stringify(d[type] || {type}));
}

/* ═══════════════════════════════════════════════════════════
   HELPERS
═══════════════════════════════════════════════════════════ */
const esc  = v => String(v??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
const attr = v => String(v??'').replace(/"/g,'&quot;');
// Allow only <b><strong><i><em><u><br><a> through — strips all other tags
const safeRich = v => String(v??'').replace(/<(?!\/?(b|strong|i|em|u|s|strike|del|br|a)(?:\s|\/?>))[^>]*>/gi, '');

/* ═══════════════════════════════════════════════════════════
   HISTORY
═══════════════════════════════════════════════════════════ */
function snap()        { return JSON.parse(JSON.stringify(blocks)); }
function pushHistory() { history.push(snap()); if(history.length>60)history.shift(); future=[]; }

function undo() {
  if(!history.length) return;
  future.push(snap());
  blocks = history.pop();
  if(sel >= blocks.length) sel = blocks.length - 1;
  render(); sync();
}
function redo() {
  if(!future.length) return;
  history.push(snap());
  blocks = future.pop();
  if(sel >= blocks.length) sel = blocks.length - 1;
  render(); sync();
}

/* ═══════════════════════════════════════════════════════════
   BLOCK OPERATIONS
═══════════════════════════════════════════════════════════ */
function addBlock(type, afterIndex) {
  pushHistory();
  const b   = blockDefault(type);
  const idx = (afterIndex >= 0) ? afterIndex + 1 : blocks.length;
  blocks.splice(idx, 0, b);
  sel = idx;
  render(); sync(); markDirty();
}

function removeBlock(i) {
  const canvas = document.getElementById('eb-canvas');
  const card = canvas?.querySelector(`[data-block-index="${i}"]`);
  const doRemove = () => { pushHistory(); blocks.splice(i,1); sel=-1; render(); sync(); markDirty(); };
  if(card) { card.classList.add('removing'); setTimeout(doRemove, 195); }
  else doRemove();
}

function duplicateBlock(i) {
  if(i<0||i>=blocks.length) return;
  pushHistory();
  const copy = JSON.parse(JSON.stringify(blocks[i]));
  blocks.splice(i+1, 0, copy);
  sel = i+1;
  render(); sync(); markDirty();
}

function moveBlock(from, to) {
  if(from === to) return;
  pushHistory();
  const [b] = blocks.splice(from, 1);
  const adj = to > from ? to - 1 : to;
  blocks.splice(adj, 0, b);
  sel = adj;
  render(); sync(); markDirty();
}

function moveUD(i, delta) {
  const t = i + delta;
  if(t<0||t>=blocks.length) return;
  pushHistory();
  [blocks[i], blocks[t]] = [blocks[t], blocks[i]];
  sel = t;
  render(); sync(); markDirty();
  requestAnimationFrame(() => {
    const canvas = document.getElementById('eb-canvas');
    const card = canvas?.querySelector(`[data-block-index="${t}"]`);
    if(card) {
      const cls = delta < 0 ? 'anim-up' : 'anim-down';
      card.classList.add(cls);
      setTimeout(() => card.classList.remove(cls), 230);
    }
  });
}

let _lastHistoryPush = 0;
function updateBlock(i, props, fromPanel) {
  if(i<0||i>=blocks.length) return;
  // Debounced history push for property edits (captures state before first change in a burst)
  const now = Date.now();
  if(now - _lastHistoryPush > 800) { pushHistory(); _lastHistoryPush = now; }
  Object.assign(blocks[i], props);
  renderCanvas();
  if(!fromPanel) renderProps();
  sync();
  markDirty();
}

/* ═══════════════════════════════════════════════════════════
   SYNC + DIRTY
═══════════════════════════════════════════════════════════ */
function sync() {
  const inp = document.getElementById('eb-blocks-input');
  if(inp) inp.value = JSON.stringify(blocks);
  const undoBtn = document.getElementById('btn-undo');
  const redoBtn = document.getElementById('btn-redo');
  if(undoBtn) undoBtn.disabled = !history.length;
  if(redoBtn) redoBtn.disabled = !future.length;
}

function markDirty() {
  dirty = true;
  const dot = document.getElementById('eb-dirty-dot');
  if(dot) dot.classList.add('visible');
}

function clearDirty() {
  dirty = false;
  const dot = document.getElementById('eb-dirty-dot');
  if(dot) dot.classList.remove('visible');
}

/* ═══════════════════════════════════════════════════════════
   RENDER PIPELINE
═══════════════════════════════════════════════════════════ */
function render() { renderCanvas(); renderProps(); }

/* ── Canvas ─────────────────────────────────────────────── */
function renderCanvas() {
  const canvas = document.getElementById('eb-canvas');
  if(!canvas) return;

  if(!blocks.length) {
    canvas.innerHTML = `
      <div class="eb-empty-state">
        <div style="font-size:36px;margin-bottom:8px;">📭</div>
        <div>Niciun bloc adăugat încă.</div>
        <div>Trage un tip de bloc din stânga sau</div>
        <button type="button" class="btn btn-secondary" style="margin-top:10px;font-size:12px;" onclick="ebShowStarterModal()">✦ Alege un template de start</button>
      </div>
      <div class="eb-drop-zone" data-drop-index="0"></div>`;
  } else {
    let h = '<div class="eb-drop-zone" data-drop-index="0"></div>';
    blocks.forEach((block, i) => {
      h += blockCard(block, i);
      h += `<div class="eb-drop-zone" data-drop-index="${i+1}"></div>`;
    });
    canvas.innerHTML = h;
  }

  // Click to select
  canvas.querySelectorAll('.eb-block-card').forEach(card => {
    const i = +card.dataset.blockIndex;
    card.addEventListener('click', e => {
      if(e.target.closest('[data-action]')) return;
      if(sel === i) return;
      canvas.querySelectorAll('.eb-block-card.selected').forEach(c => c.classList.remove('selected'));
      card.classList.add('selected');
      sel = i;
      renderProps();
    });
  });

  // Action buttons
  canvas.querySelectorAll('[data-action="remove"]').forEach(btn => btn.addEventListener('click', () => removeBlock(+btn.dataset.index)));
  canvas.querySelectorAll('[data-action="up"]').forEach(btn => btn.addEventListener('click', () => moveUD(+btn.dataset.index, -1)));
  canvas.querySelectorAll('[data-action="down"]').forEach(btn => btn.addEventListener('click', () => moveUD(+btn.dataset.index, +1)));
  canvas.querySelectorAll('[data-action="dup"]').forEach(btn => btn.addEventListener('click', (e) => { e.stopPropagation(); duplicateBlock(+btn.dataset.index); }));

  // Drag handles → make card draggable
  canvas.querySelectorAll('.eb-block-drag-handle').forEach(handle => {
    const card = handle.closest('.eb-block-card');
    card.setAttribute('draggable', 'true');
    card.addEventListener('dragstart', e => {
      dragState = {source:'canvas', index: +card.dataset.blockIndex};
      e.dataTransfer.effectAllowed = 'move';
      setTimeout(()=>card.classList.add('dragging'), 0);
      document.getElementById('eb-canvas-inner')?.classList.add('dragging-active');
    });
    card.addEventListener('dragend', () => {
      card.classList.remove('dragging');
      dragState = null;
      canvas.querySelectorAll('.eb-drop-zone.drag-over').forEach(z=>z.classList.remove('drag-over'));
      document.getElementById('eb-canvas-inner')?.classList.remove('dragging-active');
    });
  });

  // Drop zones
  canvas.querySelectorAll('.eb-drop-zone').forEach(zone => {
    zone.addEventListener('dragover', e => {
      e.preventDefault();
      if(dragState) e.dataTransfer.dropEffect = dragState.source==='palette' ? 'copy' : 'move';
      canvas.querySelectorAll('.eb-drop-zone.drag-over').forEach(z=>z.classList.remove('drag-over'));
      zone.classList.add('drag-over');
    });
    zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
    zone.addEventListener('drop', e => {
      e.preventDefault();
      zone.classList.remove('drag-over');
      const dropIndex = +zone.dataset.dropIndex;
      if(!dragState) return;
      if(dragState.source==='palette') {
        addBlock(dragState.type, dropIndex - 1);
      } else if(dragState.source==='canvas') {
        moveBlock(dragState.index, dropIndex);
      }
      dragState = null;
    });
  });

  // Column cell drag targets (from palette only)
  canvas.querySelectorAll('.eb-col-cell').forEach(cell => {
    cell.addEventListener('dragover', e => {
      if(!dragState||dragState.source!=='palette') return;
      e.preventDefault();
      e.dataTransfer.dropEffect = 'copy';
      canvas.querySelectorAll('.eb-col-cell.col-drag-over').forEach(c=>c.classList.remove('col-drag-over'));
      cell.classList.add('col-drag-over');
    });
    cell.addEventListener('dragleave', () => cell.classList.remove('col-drag-over'));
    cell.addEventListener('drop', e => {
      e.preventDefault();
      e.stopPropagation();
      cell.classList.remove('col-drag-over');
      if(!dragState||dragState.source!=='palette') return;
      const blockI = +cell.dataset.blockI;
      const ci = +cell.dataset.colCi;
      const newCols = JSON.parse(JSON.stringify(blocks[blockI].columns||[]));
      const cnt = blocks[blockI].type==='columns_2'?2:3;
      while(newCols.length<cnt) newCols.push([]);
      newCols[ci] = [...(newCols[ci]||[]), blockDefault(dragState.type)];
      pushHistory();
      Object.assign(blocks[blockI], {columns: newCols});
      sel = blockI;
      dragState = null;
      document.getElementById('eb-canvas-inner')?.classList.remove('dragging-active');
      render(); sync(); markDirty();
    });
  });

  // Click on canvas background to deselect
  canvas.addEventListener('click', e => {
    if(!e.target.closest('.eb-block-card') && !e.target.closest('.eb-drop-zone')) {
      if(sel >= 0) { sel = -1; renderCanvas(); renderProps(); }
    }
  }, {capture: false});

  // Highlight selected
  if(sel >= 0) {
    const sc = canvas.querySelector(`[data-block-index="${sel}"]`);
    if(sc) sc.classList.add('selected');
  }
}

function blockCard(block, i) {
  const info    = BLOCK_TYPES[block.type] || {label:block.type, icon:'?'};
  const isSel   = sel === i;
  const preview = blockPreview(block, i);
  const isFirst = i === 0;
  const isLast  = i === blocks.length - 1;
  const hidden = [];
  if(block.hide_desktop) hidden.push('desktop');
  if(block.hide_tablet)  hidden.push('tabletă');
  if(block.hide_mobile)  hidden.push('mobil');
  const hiddenBadge = hidden.length ? `<span class="eb-block-hidden-badge" title="Ascuns pe: ${hidden.join(', ')}" style="font-size:10px;color:#b45309;background:#fef3c7;border-radius:3px;padding:1px 6px;margin-left:4px;">🚫 ${hidden.join(', ')}</span>` : '';
  return `
    <div class="eb-block-card${isSel?' selected':''}" data-block-index="${i}">
      <div class="eb-block-toolbar">
        <span class="eb-block-drag-handle" title="Trage pentru reordonare">⠿</span>
        <span class="eb-block-type-tag">${info.icon} ${info.label}</span>${hiddenBadge}
        <div class="eb-block-btns">
          <button type="button" data-action="up"   data-index="${i}" ${isFirst?'disabled':''} title="Mută sus">↑</button>
          <button type="button" data-action="down" data-index="${i}" ${isLast?'disabled':''} title="Mută jos">↓</button>
          <button type="button" data-action="dup"  data-index="${i}" title="Duplică">⧉</button>
          <button type="button" data-action="remove" data-index="${i}" class="danger" title="Șterge">✕</button>
        </div>
      </div>
      <div class="eb-block-preview">${preview}</div>
    </div>`;
}

/* ── Block Preview (canvas visual) ─────────────────────── */
function subBlockMini(sb) {
  const inner = subBlockMiniInner(sb);
  const pt=+(sb.padding_top||0), pb=+(sb.padding_bottom||0), pl=+(sb.padding_left||0), pr=+(sb.padding_right||0);
  return (pt||pb||pl||pr) ? `<div style="padding:${pt}px ${pr}px ${pb}px ${pl}px;">${inner}</div>` : inner;
}
function subBlockMiniInner(sb) {
  const bg  = sb.background||'#ffffff';
  const tc  = sb.text_color||'#333333';
  const ff  = ebFontStack(sb.font_family);
  const fontStyle = ff ? `font-family:${ff};` : '';
  switch(sb.type) {
    case 'header': return `<div style="background:${esc(bg)};color:${esc(tc)};${fontStyle}font-size:${+(sb.font_size||24)}px;font-weight:700;padding:4px 6px;line-height:1.2;">${safeRich((sb.content||'Header').substring(0,50))}</div>`;
    case 'text':   return `<div style="background:${esc(bg)};color:${esc(tc)};${fontStyle}font-size:${+(sb.font_size||14)}px;padding:4px 6px;line-height:1.4;white-space:pre-wrap;">${safeRich((sb.content||'Text').substring(0,120))}</div>`;
    case 'image':  return sb.image_url ? `<div style="background:${esc(bg)};padding:3px;text-align:${sb.align||'center'};"><img src="${attr(sb.image_url)}" alt="" style="width:${+(sb.width||100)}%;max-width:100%;height:auto;border-radius:${+(sb.border_radius||0)}px;display:inline-block;vertical-align:top;"></div>` : `<div style="background:${esc(bg)};padding:4px 6px;color:#9ca3af;font-size:10px;border:1px dashed #d1d5db;border-radius:3px;">🖼 Imagine</div>`;
    case 'button': { const bc=sb.button_color||'#2d7d5f'; return `<div style="background:${esc(bg)};padding:4px 6px;text-align:${sb.align||'left'};"><span style="display:inline-block;background:${esc(bc)};color:${esc(tc)};padding:${Math.min(+(sb.btn_pad_v??8),10)}px ${Math.min(+(sb.btn_pad_h??18),16)}px;border-radius:${+(sb.border_radius||6)}px;font-size:${Math.min(+(sb.font_size||14),16)}px;font-weight:600;">${esc(sb.label||'Buton')}</span></div>`; }
    case 'divider':return `<div style="background:${esc(bg)};padding:4px 6px;"><hr style="border:none;border-top:${+(sb.thickness||1)}px solid ${esc(sb.color||'#e5e7eb')};margin:0;"></div>`;
    case 'spacer': return `<div style="background:${esc(bg)};height:${Math.min(+(sb.height||16),24)}px;"></div>`;
    default:       return `<div style="padding:3px 6px;font-size:10px;color:#6b7280;">${esc(sb.type||'?')}</div>`;
  }
}

function socialIconSvg(platform, size) {
  const s = size||32, half = Math.round(s*.5);
  const icons = {
    facebook:  {c:'#1877f2',p:'M18 2h-3a5 5 0 00-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3z'},
    instagram: {c:'#e1306c',p:'M12 2.163c3.204 0 3.584.012 4.85.07 1.366.062 2.633.334 3.608 1.308.975.975 1.246 2.242 1.308 3.608.058 1.266.07 1.646.07 4.85s-.012 3.584-.07 4.85c-.062 1.366-.334 2.633-1.308 3.608-.975.975-2.242 1.246-3.608 1.308-1.266.058-1.646.07-4.85.07s-3.584-.012-4.85-.07c-1.366-.062-2.633-.334-3.608-1.308-.975-.975-1.246-2.242-1.308-3.608C2.175 15.584 2.163 15.204 2.163 12s.012-3.584.07-4.85c.062-1.366.334-2.633 1.308-3.608.975-.975 2.242-1.246 3.608-1.308C8.416 2.175 8.796 2.163 12 2.163zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z'},
    twitter:   {c:'#000000',p:'M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.744l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z'},
    linkedin:  {c:'#0a66c2',p:'M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z'},
    youtube:   {c:'#ff0000',p:'M23.495 6.205a3.007 3.007 0 00-2.088-2.088c-1.87-.501-9.396-.501-9.396-.501s-7.507-.01-9.396.501A3.007 3.007 0 00.527 6.205a31.247 31.247 0 00-.522 5.805 31.247 31.247 0 00.522 5.783 3.007 3.007 0 002.088 2.088c1.868.502 9.396.502 9.396.502s7.506 0 9.396-.502a3.007 3.007 0 002.088-2.088 31.247 31.247 0 00.5-5.783 31.247 31.247 0 00-.5-5.805zM9.609 15.601V8.408l6.264 3.602z'},
    tiktok:    {c:'#010101',p:'M19.59 6.69a4.83 4.83 0 01-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 01-2.88 2.5 2.89 2.89 0 01-2.89-2.89 2.89 2.89 0 012.89-2.89c.28 0 .54.04.79.1V9.01a6.33 6.33 0 00-.79-.05 6.34 6.34 0 00-6.34 6.34 6.34 6.34 0 006.34 6.34 6.34 6.34 0 006.33-6.34V9.02a8.19 8.19 0 004.77 1.52V7.1a4.85 4.85 0 01-1-.41z'},
    pinterest: {c:'#e60023',p:'M12.017 0C5.396 0 .029 5.367.029 11.987c0 5.079 3.158 9.417 7.618 11.162-.105-.949-.2-2.405.042-3.441.218-.937 1.407-5.965 1.407-5.965s-.359-.719-.359-1.782c0-1.668.967-2.914 2.171-2.914 1.023 0 1.518.769 1.518 1.69 0 1.029-.655 2.568-.994 3.995-.283 1.194.599 2.169 1.777 2.169 2.133 0 3.772-2.249 3.772-5.495 0-2.873-2.064-4.882-5.012-4.882-3.414 0-5.418 2.561-5.418 5.207 0 1.031.397 2.138.893 2.738a.36.36 0 01.083.345l-.333 1.36c-.053.22-.174.267-.402.161-1.499-.698-2.436-2.889-2.436-4.649 0-3.785 2.75-7.262 7.929-7.262 4.163 0 7.398 2.967 7.398 6.931 0 4.136-2.607 7.464-6.227 7.464-1.216 0-2.359-.632-2.75-1.378l-.748 2.853c-.271 1.043-1.002 2.35-1.492 3.146C9.57 23.812 10.763 24 12.017 24c6.624 0 11.99-5.367 11.99-11.987C24.007 5.367 18.641.001 12.017.001z'},
  };
  const ico = icons[platform] || icons.facebook;
  return `<span style="display:inline-flex;align-items:center;justify-content:center;width:${s}px;height:${s}px;background:${ico.c};border-radius:50%;margin:0 3px;flex-shrink:0;"><svg viewBox="0 0 24 24" width="${half}px" height="${half}px" fill="#fff" xmlns="http://www.w3.org/2000/svg"><path d="${ico.p}"/></svg></span>`;
}

function ebFontStack(key) {
  const m = {arial:"Arial, Helvetica, sans-serif",helvetica:"Helvetica, Arial, sans-serif",verdana:"Verdana, Geneva, sans-serif",tahoma:"Tahoma, Geneva, sans-serif",trebuchet:"'Trebuchet MS', Helvetica, sans-serif",georgia:"Georgia, 'Times New Roman', serif",times:"'Times New Roman', Times, serif",palatino:"'Palatino Linotype', 'Book Antiqua', Palatino, serif",courier:"'Courier New', Courier, monospace",lucida:"'Lucida Sans Unicode', 'Lucida Grande', sans-serif"};
  return m[String(key||'').toLowerCase()] || '';
}
function blockPreview(b, blockIdx) {
  const bg  = b.background||'#ffffff';
  const tc  = b.text_color||'#333333';
  const ali = b.align||'left';
  const pl  = +(b.padding_left||0);
  const pr  = +(b.padding_right||0);
  const pt  = +(b.padding_top||0);
  const pb  = +(b.padding_bottom||0);
  const hPad = pl||pr ? `padding-left:${pl}px;padding-right:${pr}px;` : '';
  const vPad = `padding-top:${pt}px;padding-bottom:${pb}px;`;
  const ff = ebFontStack(b.font_family);
  const fontStyle = ff ? `font-family:${ff};` : '';
  const bgImg = b.bg_image ? `;background-image:url('${attr(b.bg_image)}');background-size:${esc(b.bg_size||'cover')};background-repeat:no-repeat;background-position:center` : '';
  switch(b.type) {
    case 'header':
      return `<div style="background:${esc(bg)}${bgImg};${vPad}padding-left:${pl}px;padding-right:${pr}px;${fontStyle}color:${esc(tc)};text-align:${ali};font-size:${Math.min(+(b.font_size||32),40)}px;font-weight:700;line-height:1.2;">${safeRich(b.content||'Header')}</div>`;
    case 'text':
      return `<div style="background:${esc(bg)}${bgImg};${vPad}padding-left:${pl}px;padding-right:${pr}px;${fontStyle}color:${esc(tc)};text-align:${ali};font-size:${+(b.font_size||16)}px;line-height:1.6;white-space:pre-wrap;">${safeRich(b.content||'Text...')}</div>`;
    case 'image':
      return b.image_url
        ? `<div style="background:${esc(bg)}${bgImg};${vPad}padding-left:${pl}px;padding-right:${pr}px;text-align:${ali};"><img src="${attr(b.image_url)}" alt="${attr(b.alt||'')}" style="width:${+(b.width||100)}%;max-width:100%;height:auto;border-radius:${+(b.border_radius||0)}px;display:inline-block;vertical-align:top;"></div>`
        : `<div style="background:${esc(bg)}${bgImg};padding:12px 16px;text-align:center;color:#9ca3af;border:2px dashed #d1d5db;border-radius:4px;">🖼 Nicio imagine selectată</div>`;
    case 'button': {
      const bc = b.button_color||'#2d7d5f';
      const btc = b.text_color||'#ffffff';
      return `<div style="background:${esc(bg)}${bgImg};${vPad}padding-left:${pl}px;padding-right:${pr}px;text-align:${ali};"><span style="display:inline-block;background:${esc(bc)};color:${esc(btc)};padding:${+(b.btn_pad_v??11)}px ${+(b.btn_pad_h??22)}px;border-radius:${+(b.border_radius||6)}px;font-weight:600;font-size:${+(b.font_size||16)}px;">${esc(b.label||'Buton')}</span></div>`;
    }
    case 'divider':
      return `<div style="background:${esc(bg)}${bgImg};${vPad}padding-left:${pl}px;padding-right:${pr}px;"><hr style="border:none;border-top:${+(b.thickness||1)}px solid ${esc(b.color||'#e5e7eb')};margin:0 auto;width:${+(b.width||80)}%;"></div>`;
    case 'spacer':
      return `<div style="background:${esc(bg)}${bgImg};min-height:${+(b.height||24)}px;display:flex;align-items:center;justify-content:center;"><span style="color:#d1d5db;font-size:11px;">↕ ${+(b.height||24)}px</span></div>`;
    case 'quote': {
      const qbc = b.border_color||'#2d7d5f';
      return `<div style="background:${esc(bg)}${bgImg};border-left:4px solid ${esc(qbc)};${vPad}padding-left:${pl||16}px;padding-right:${pr}px;${fontStyle}"><div style="color:${esc(tc)};font-style:italic;font-size:${+(b.font_size||16)}px;">"${safeRich(b.content||'Citatul tău...')}"</div>${b.author?`<div style="font-size:12px;color:${esc(tc)};opacity:.65;margin-top:5px;">— ${esc(b.author)}</div>`:''}</div>`;
    }
    case 'social': {
      const sz = +(b.icon_size||32);
      const lnks = (b.links||[]).map(l => socialIconSvg(l.platform||'facebook', sz)).join('');
      return `<div style="background:${esc(bg)}${bgImg};${vPad}padding-left:${pl}px;padding-right:${pr}px;text-align:${ali};">${lnks||'<span style="color:#9ca3af;">Niciun link social</span>'}</div>`;
    }
    case 'columns_2':
    case 'columns_2_6040':
    case 'columns_2_4060':
    case 'columns_2_7030':
    case 'columns_2_3070':
    case 'columns_3': {
      const widths = colWidths(b.type);
      const colCount = widths.length;
      const cols = b.columns || Array.from({length:colCount}, ()=>[]);
      const mkCell = (ci) => {
        const cbs = cols[ci]||[];
        const inner = cbs.length ? cbs.map(sb=>subBlockMini(sb)).join('') : `<span style="color:#9ca3af;font-size:9px;">Trage bloc</span>`;
        return `<div class="eb-col-cell" data-block-i="${blockIdx}" data-col-ci="${ci}" style="flex:0 0 ${widths[ci]}%;max-width:${widths[ci]}%;display:flex;flex-direction:column;align-items:stretch;justify-content:${cbs.length?'flex-start':'center'}">${inner}</div>`;
      };
      return `<div style="background:${esc(bg)}${bgImg};display:flex;gap:6px;padding-top:8px;padding-bottom:8px;padding-left:${pl||8}px;padding-right:${pr||8}px;">${widths.map((_,ci)=>mkCell(ci)).join('')}</div>`;
    }
    default:
      return `<div style="padding:10px 16px;color:#6b7280;font-size:12px;">[${esc(b.type||'?')}]</div>`;
  }
}

/* ═══════════════════════════════════════════════════════════
   PROPERTIES PANEL
═══════════════════════════════════════════════════════════ */
function renderProps() {
  const panel = document.getElementById('eb-props-body');
  if(!panel) return;
  if(sel<0||!blocks[sel]) {
    panel.innerHTML = '<div class="eb-props-empty">Selectează un bloc din canvas pentru a-i edita proprietățile.</div>';
    return;
  }
  panel.innerHTML = buildPropsHtml(blocks[sel], sel);
  attachPropEvents(panel, sel);
}

function buildPropsHtml(b, i) {
  const rows = [];

  const group = (label, content) => `<div class="eb-props-group"><label>${label}</label>${content}</div>`;
  const textarea = (key, val, ph='') => `<textarea data-key="${key}" rows="3">${esc(val)}</textarea>`;
  const textInput = (key, val, ph='') => `<input type="text" data-key="${key}" value="${attr(val)}" placeholder="${attr(ph)}">`;
  const numInput  = (key, val, min=0, max=999, step=1) => `<input type="number" data-key="${key}" value="${+val||0}" min="${min}" max="${max}" step="${step}">`;
  const urlInput  = (key, val) => `<input type="url" data-key="${key}" value="${attr(val)}" placeholder="https://...">`;
  const sep       = () => `<hr class="eb-props-sep">`;
  const EB_FONTS = [
    ['','Implicit (Arial)'],['arial','Arial'],['helvetica','Helvetica'],['verdana','Verdana'],
    ['tahoma','Tahoma'],['trebuchet','Trebuchet MS'],['georgia','Georgia'],['times','Times New Roman'],
    ['palatino','Palatino'],['courier','Courier New'],['lucida','Lucida Sans'],
  ];
  const fontSelect = (val) => `<select data-key="font_family">${EB_FONTS.map(([k,l])=>`<option value="${k}" ${(val||'')===k?'selected':''}>${l}</option>`).join('')}</select>`;
  const spacingGroup = (b2) => group('Margini secțiune (px)', `
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
      <label style="font-size:11px;color:#6b7280;display:flex;align-items:center;gap:4px;">↑ Sus<input type="number" data-key="padding_top" value="${+(b2&&b2.padding_top||0)}" min="0" max="200" style="width:100%;"></label>
      <label style="font-size:11px;color:#6b7280;display:flex;align-items:center;gap:4px;">↓ Jos<input type="number" data-key="padding_bottom" value="${+(b2&&b2.padding_bottom||0)}" min="0" max="200" style="width:100%;"></label>
      <label style="font-size:11px;color:#6b7280;display:flex;align-items:center;gap:4px;">← Stânga<input type="number" data-key="padding_left" value="${+(b2&&b2.padding_left||0)}" min="0" max="200" style="width:100%;"></label>
      <label style="font-size:11px;color:#6b7280;display:flex;align-items:center;gap:4px;">Dreapta →<input type="number" data-key="padding_right" value="${+(b2&&b2.padding_right||0)}" min="0" max="200" style="width:100%;"></label>
    </div>`);

  const bgImageRow = (b2, blockIdx) => `
    <div class="eb-image-row">
      ${textInput('bg_image', b2.bg_image||'', 'URL imagine fundal...')}
      <button type="button" class="btn btn-secondary" onclick="ebOpenImgModal(${blockIdx},'bg_image')">📁</button>
    </div>
    <div style="margin-top:4px;display:flex;align-items:center;gap:6px;">
      <label style="font-size:11px;color:#6b7280;">Dimensiune:</label>
      <select data-key="bg_size" style="flex:1;padding:3px 5px;font-size:11px;border:1px solid #d1d5db;border-radius:4px;">
        <option value="cover" ${(b2.bg_size||'cover')==='cover'?'selected':''}>Cover</option>
        <option value="contain" ${b2.bg_size==='contain'?'selected':''}>Contain</option>
        <option value="auto" ${b2.bg_size==='auto'?'selected':''}>Auto</option>
      </select>
    </div>`;

  const colorRow = (key, val) => `
    <div class="eb-color-row">
      <input type="color" data-color-target="${key}" value="${attr(val||'#ffffff')}">
      <input type="text" data-key="${key}" value="${attr(val||'#ffffff')}" placeholder="#ffffff">
    </div>`;

  const alignRow = (key, val, b) => `
    <div class="eb-align-row-wrap">
      <div class="eb-align-row">
        <button type="button" data-align-key="${key}" data-val="left"  class="${val==='left' ?'active':''}"><span>Stânga</span><span>↤</span></button>
        <button type="button" data-align-key="${key}" data-val="center" class="${val==='center'?'active':''}"><span>Centru</span><span>↔</span></button>
        <button type="button" data-align-key="${key}" data-val="right" class="${val==='right'?'active':''}"><span>Dreapta</span><span>↦</span></button>
        <button type="button" class="eb-align-gear" title="Setează margini">⚙</button>
      </div>
      <div class="eb-align-margins" style="display:none;">
        <label style="font-size:11px;color:#6b7280;display:flex;align-items:center;gap:6px;margin-bottom:4px;">
          ↑ Margine sus (px):
          <input type="number" data-key="padding_top" value="${+(b&&b.padding_top||0)}" min="0" max="200" step="1" style="width:64px;">
        </label>
        <label style="font-size:11px;color:#6b7280;display:flex;align-items:center;gap:6px;margin-bottom:4px;">
          ↓ Margine jos (px):
          <input type="number" data-key="padding_bottom" value="${+(b&&b.padding_bottom||0)}" min="0" max="200" step="1" style="width:64px;">
        </label>
        <label style="font-size:11px;color:#6b7280;display:flex;align-items:center;gap:6px;margin-bottom:4px;">
          ← Margine stânga (px):
          <input type="number" data-key="padding_left" value="${+(b&&b.padding_left||0)}" min="0" max="200" step="1" style="width:64px;">
        </label>
        <label style="font-size:11px;color:#6b7280;display:flex;align-items:center;gap:6px;">
          Margine dreapta (px) →:
          <input type="number" data-key="padding_right" value="${+(b&&b.padding_right||0)}" min="0" max="200" step="1" style="width:64px;">
        </label>
      </div>
    </div>`;

  const richTextarea = (key, val) => `
    <div class="eb-rich-bar">
      <button type="button" class="eb-rich-btn" data-rich-tag="b" title="Bold"><b>B</b></button>
      <button type="button" class="eb-rich-btn" data-rich-tag="i" title="Italic"><i>I</i></button>
      <button type="button" class="eb-rich-btn" data-rich-tag="u" title="Subliniat"><u>U</u></button>
      <button type="button" class="eb-rich-btn" data-rich-tag="s" title="Tăiat"><s>S</s></button>
    </div>
    <textarea data-key="${key}" rows="3">${esc(val)}</textarea>`;

  switch(b.type) {
    case 'header':
    case 'text':
      rows.push(group('Conținut', richTextarea('content', b.content||'')));
      rows.push(group('Font', fontSelect(b.font_family||'')));
      rows.push(group('Aliniere', alignRow('align', b.align||'left', b)));
      rows.push(group('Mărime text desktop (px)', numInput('font_size', b.font_size||16, 8, 96)));
      rows.push(group('Mărime text mobil (px)', numInput('font_size_mobile', b.font_size_mobile||14, 8, 96)));
      rows.push(sep());
      rows.push(group('Fundal', colorRow('background', b.background||'#ffffff')));
      rows.push(group('Imagine fundal', bgImageRow(b, i)));
      rows.push(group('Culoare text', colorRow('text_color', b.text_color||'#333333')));
      break;

    case 'image':
      rows.push(group('URL imagine', `<div class="eb-image-row">${textInput('image_url', b.image_url||'', 'https://...')}<button type="button" class="btn btn-secondary" onclick="ebOpenImgModal(${i})">📁</button></div>`));
      rows.push(group('Lățime (%)', `${numInput('width', b.width||100, 10, 100)} <input type="range" data-key="width" value="${+(b.width||100)}" min="10" max="100">`));
      rows.push(group('Rotunjire colțuri (px)', `${numInput('border_radius', b.border_radius||0, 0, 80)} <input type="range" data-key="border_radius" value="${+(b.border_radius||0)}" min="0" max="80">`));
      rows.push(group('Alt text', textInput('alt', b.alt||'', 'Descriere imagine')));
      rows.push(group('Link (opțional)', urlInput('link_url', b.link_url||'')));
      rows.push(group('Aliniere', alignRow('align', b.align||'center', b)));
      rows.push(sep());
      rows.push(group('Fundal', colorRow('background', b.background||'#ffffff')));
      rows.push(group('Imagine fundal', bgImageRow(b, i)));
      break;

    case 'button':
      rows.push(group('Text buton', textInput('label', b.label||'Apasă aici')));
      rows.push(group('URL buton', urlInput('url', b.url||'')));
      rows.push(group('Aliniere', alignRow('align', b.align||'center', b)));
      rows.push(group('Mărime text (px)', numInput('font_size', b.font_size||16, 10, 40)));
      rows.push(group('Mărime buton (spațiere px)', `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
          <label style="font-size:11px;color:#6b7280;display:flex;align-items:center;gap:4px;">↕ Vertical<input type="number" data-key="btn_pad_v" value="${+(b.btn_pad_v??11)}" min="2" max="40" style="width:100%;"></label>
          <label style="font-size:11px;color:#6b7280;display:flex;align-items:center;gap:4px;">↔ Orizontal<input type="number" data-key="btn_pad_h" value="${+(b.btn_pad_h??22)}" min="4" max="80" style="width:100%;"></label>
        </div>`));
      rows.push(sep());
      rows.push(group('Culoare buton', colorRow('button_color', b.button_color||'#2d7d5f')));
      rows.push(group('Culoare text', colorRow('text_color', b.text_color||'#ffffff')));
      rows.push(group('Rounding colțuri (px)', numInput('border_radius', b.border_radius||6, 0, 50)));
      rows.push(sep());
      rows.push(group('Fundal secțiune', colorRow('background', b.background||'#ffffff')));
      rows.push(group('Imagine fundal', bgImageRow(b, i)));
      break;

    case 'divider':
      rows.push(group('Culoare linie', colorRow('color', b.color||'#e5e7eb')));
      rows.push(group('Lățime (%)', numInput('width', b.width||80, 10, 100)));
      rows.push(group('Grosime (px)', numInput('thickness', b.thickness||1, 1, 10)));
      rows.push(spacingGroup(b));
      rows.push(sep());
      rows.push(group('Fundal secțiune', colorRow('background', b.background||'#ffffff')));
      rows.push(group('Imagine fundal', bgImageRow(b, i)));
      break;

    case 'spacer':
      rows.push(group('Înălțime (px)', `${numInput('height', b.height||24, 4, 200)} <input type="range" data-key="height" value="${+(b.height||24)}" min="4" max="200">`));
      rows.push(spacingGroup(b));
      rows.push(group('Fundal secțiune', colorRow('background', b.background||'#ffffff')));
      rows.push(group('Imagine fundal', bgImageRow(b, i)));
      break;

    case 'quote':
      rows.push(group('Citat', richTextarea('content', b.content||'')));
      rows.push(group('Autor', textInput('author', b.author||'', 'Opțional...')));
      rows.push(group('Font', fontSelect(b.font_family||'')));
      rows.push(group('Aliniere', alignRow('align', b.align||'left', b)));
      rows.push(group('Mărime text (px)', numInput('font_size', b.font_size||16, 10, 40)));
      rows.push(sep());
      rows.push(group('Fundal', colorRow('background', b.background||'#f0fdf4')));
      rows.push(group('Imagine fundal', bgImageRow(b, i)));
      rows.push(group('Culoare text', colorRow('text_color', b.text_color||'#166534')));
      rows.push(group('Culoare bordură', colorRow('border_color', b.border_color||'#2d7d5f')));
      break;

    case 'social': {
      const linksHtml = buildSocialLinksHtml(b.links||[], i);
      rows.push(group('Link-uri sociale', linksHtml));
      rows.push(group('Dimensiune icoane (px)', numInput('icon_size', b.icon_size||32, 20, 80)));
      rows.push(group('Aliniere', alignRow('align', b.align||'center', b)));
      rows.push(sep());
      rows.push(group('Fundal', colorRow('background', b.background||'#ffffff')));
      rows.push(group('Imagine fundal', bgImageRow(b, i)));
      break;
    }

    case 'columns_2':
    case 'columns_2_6040':
    case 'columns_2_4060':
    case 'columns_2_7030':
    case 'columns_2_3070':
    case 'columns_3': {
      const count = colWidths(b.type).length;
      const cols  = b.columns || Array.from({length:count}, ()=>[]);
      const subTypeOpts = [['text','Text'],['header','Header'],['image','Imagine'],['button','Buton'],['divider','Separator'],['spacer','Spațiu']];
      rows.push(group('Spațiu între coloane (px)', numInput('gap', b.gap||16, 0, 60)));
      rows.push(spacingGroup(b));
      rows.push(group('Fundal secțiune', colorRow('background', b.background||'#ffffff')));
      rows.push(group('Imagine fundal', bgImageRow(b, i)));
      rows.push(sep());
      for(let ci=0; ci<count; ci++) {
        const cb2 = cols[ci]||[];
        let colH = `<div class="eb-col-blocks" data-col="${ci}">`;
        if(cb2.length===0) {
          colH += `<div style="color:#9ca3af;font-size:11px;text-align:center;padding:6px;border:1px dashed #e5e7eb;border-radius:4px;margin-bottom:4px;">Goală</div>`;
        } else {
          cb2.forEach((sb,cbi) => {
            const sbi = BLOCK_TYPES[sb.type]||{icon:'?',label:sb.type};
            colH += `<div class="eb-col-block-row" data-col="${ci}" data-cbi="${cbi}" style="display:flex;align-items:center;gap:4px;padding:3px 6px;border:1px solid #e5e7eb;border-radius:4px;margin-bottom:3px;cursor:pointer;background:#f9fafb;" title="Click pentru editare">
              <span style="font-size:11px;flex:1;color:#374151;">${sbi.icon} ${sbi.label}</span>
              <button type="button" data-col-move="${ci}-${cbi}-up" ${cbi===0?'disabled':''} title="Mută sus" style="padding:1px 5px;font-size:10px;border:1px solid #d1d5db;border-radius:3px;background:#fff;color:#374151;cursor:pointer;${cbi===0?'opacity:.35;cursor:default;':''}">↑</button>
              <button type="button" data-col-move="${ci}-${cbi}-down" ${cbi===cb2.length-1?'disabled':''} title="Mută jos" style="padding:1px 5px;font-size:10px;border:1px solid #d1d5db;border-radius:3px;background:#fff;color:#374151;cursor:pointer;${cbi===cb2.length-1?'opacity:.35;cursor:default;':''}">↓</button>
              <button type="button" data-col-remove="${ci}-${cbi}" style="padding:1px 5px;font-size:10px;border:1px solid #fca5a5;border-radius:3px;background:#fff;color:#dc2626;cursor:pointer;">✕</button>
            </div>
            <div class="eb-col-subprops" id="eb-col-sp-${ci}-${cbi}" style="display:none;padding:6px;border:1px solid #d1fae5;border-radius:4px;margin-bottom:4px;background:#f0fdf4;"></div>`;
          });
        }
        colH += `</div>`;
        colH += `<div style="display:flex;gap:4px;margin-top:4px;">
          <select data-col-add-type="${ci}" style="flex:1;padding:3px 5px;font-size:11px;border:1px solid #d1d5db;border-radius:4px;">
            ${subTypeOpts.map(([v,l])=>`<option value="${v}">${l}</option>`).join('')}
          </select>
          <button type="button" data-col-add="${ci}" class="btn" style="padding:3px 8px;font-size:11px;">+ Adaugă</button>
        </div>`;
        rows.push(group(`Coloana ${ci+1}`, colH));
      }
      break;
    }

    default:
      rows.push('<div class="eb-props-empty">Tip bloc necunoscut.</div>');
  }

  if (b.type !== 'columns_2' && b.type !== 'columns_3' && !String(b.type||'').startsWith('columns_')) {
    rows.push(sep());
  }
  rows.push(group('Vizibilitate pe dispozitive', `
    <div style="display:flex;flex-direction:column;gap:5px;font-size:12px;color:#374151;">
      <label style="display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="checkbox" data-checkkey="hide_desktop" ${b.hide_desktop?'checked':''}> Ascunde pe desktop</label>
      <label style="display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="checkbox" data-checkkey="hide_tablet" ${b.hide_tablet?'checked':''}> Ascunde pe tabletă</label>
      <label style="display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="checkbox" data-checkkey="hide_mobile" ${b.hide_mobile?'checked':''}> Ascunde pe mobil</label>
      <span style="font-size:10.5px;color:#9ca3af;line-height:1.4;">Funcționează în majoritatea aplicațiilor de email (Apple Mail, iOS, Outlook). Unele versiuni de Gmail pot ignora ascunderea.</span>
    </div>`));

  return rows.join('');
}

function buildSocialLinksHtml(links, blockIndex) {
  const platforms = ['facebook','instagram','twitter','linkedin','youtube','tiktok','pinterest'];
  let html = `<div class="eb-social-links" id="eb-social-links-${blockIndex}">`;
  links.forEach((l,j) => {
    html += `<div class="eb-social-link-row" data-link-index="${j}">
      <select data-social-key="platform" data-link-index="${j}">
        ${platforms.map(p=>`<option value="${p}"${l.platform===p?' selected':''}>${p.charAt(0).toUpperCase()+p.slice(1)}</option>`).join('')}
      </select>
      <input type="url" data-social-key="url" data-link-index="${j}" value="${attr(l.url||'')}" placeholder="https://...">
      <button type="button" data-social-remove="${j}" title="Șterge">✕</button>
    </div>`;
  });
  html += `</div>`;
  html += `<button type="button" class="btn btn-secondary" style="font-size:11px;margin-top:4px;" data-social-add="${blockIndex}">+ Adaugă rețea</button>`;
  return html;
}

function attachPropEvents(panel, i) {
  // Device-visibility checkboxes
  panel.querySelectorAll('[data-checkkey]').forEach(el => {
    el.addEventListener('change', () => {
      updateBlock(i, {[el.dataset.checkkey]: el.checked ? 1 : 0}, true);
    });
  });
  // Text/textarea inputs → update block on change
  panel.querySelectorAll('[data-key]').forEach(el => {
    el.addEventListener('input', () => {
      const key = el.dataset.key;
      let val = el.value;
      if(el.type==='number'||el.type==='range') val = +val;
      // Patch padding_left/padding_right: update inner preview HTML (padding is now on the background div)
      if(key === 'padding_left' || key === 'padding_right') {
        if(i>=0 && blocks[i]) {
          blocks[i][key] = val;
          const previewEl = document.querySelector(`[data-block-index="${i}"] .eb-block-preview`);
          if(previewEl) previewEl.innerHTML = blockPreview(blocks[i], i);
          sync(); markDirty();
        }
        return;
      }
      updateBlock(i, {[key]: val}, true);
    });
  });

  // Range sliders → sync with paired number input
  panel.querySelectorAll('input[type=range][data-key]').forEach(range => {
    const key = range.dataset.key;
    const paired = panel.querySelector(`input[type=number][data-key="${key}"]`);
    range.addEventListener('input', () => {
      const v = +range.value;
      if(paired) paired.value = v;
      updateBlock(i, {[key]: v}, true);
    });
    if(paired) {
      paired.addEventListener('input', () => { range.value = paired.value; });
    }
  });

  // Color pickers
  panel.querySelectorAll('input[type=color][data-color-target]').forEach(picker => {
    const key = picker.dataset.colorTarget;
    const textEl = panel.querySelector(`input[type=text][data-key="${key}"]`);
    // Eyedropper/color drag fires 'input' continuously — coalesce to one render
    // per animation frame so the page doesn't freeze under the flood of events.
    picker.addEventListener('input', () => {
      if(textEl) textEl.value = picker.value;
      if(picker._ebRaf) return;
      picker._ebRaf = requestAnimationFrame(() => {
        picker._ebRaf = null;
        updateBlock(i, {[key]: picker.value}, true);
      });
    });
    picker.addEventListener('change', () => {
      if(picker._ebRaf) { cancelAnimationFrame(picker._ebRaf); picker._ebRaf = null; }
      if(textEl) textEl.value = picker.value;
      updateBlock(i, {[key]: picker.value}, true);
    });
    if(textEl) {
      textEl.addEventListener('change', () => {
        const v = textEl.value.trim();
        if(/^#[0-9a-f]{3,8}$/i.test(v)) { picker.value = v; updateBlock(i, {[key]: v}, true); }
      });
    }
  });

  // Rich text B/I/U buttons — wrap selected textarea text with tag
  panel.querySelectorAll('.eb-rich-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const tag = btn.dataset.richTag;
      const ta = btn.closest('.eb-props-group')?.querySelector('textarea[data-key]');
      if(!ta) return;
      const start = ta.selectionStart, end = ta.selectionEnd;
      const sel = ta.value.slice(start, end);
      const open = `<${tag}>`, close = `</${tag}>`;
      // Toggle: if selection already wrapped, unwrap; else wrap
      if(ta.value.slice(start - open.length, start) === open && ta.value.slice(end, end + close.length) === close) {
        const newVal = ta.value.slice(0, start - open.length) + sel + ta.value.slice(end + close.length);
        ta.value = newVal;
        ta.setSelectionRange(start - open.length, start - open.length + sel.length);
      } else {
        ta.setRangeText(open + sel + close, start, end, 'select');
      }
      ta.dispatchEvent(new Event('input'));
    });
  });

  // Align buttons
  panel.querySelectorAll('[data-align-key]').forEach(btn => {
    btn.addEventListener('click', () => {
      const key = btn.dataset.alignKey;
      const val = btn.dataset.val;
      panel.querySelectorAll(`[data-align-key="${key}"]`).forEach(b=>b.classList.remove('active'));
      btn.classList.add('active');
      updateBlock(i, {[key]: val}, true);
    });
  });

  // Align gear toggle
  panel.querySelectorAll('.eb-align-gear').forEach(gear => {
    gear.addEventListener('click', () => {
      const margins = gear.closest('.eb-align-row-wrap').querySelector('.eb-align-margins');
      if(margins) { const vis = margins.style.display==='none'; margins.style.display = vis ? 'block' : 'none'; gear.style.opacity = vis ? '1' : ''; }
    });
  });

  // Social link events
  panel.querySelectorAll('[data-social-key]').forEach(el => {
    el.addEventListener('change', () => {
      const j   = +el.dataset.linkIndex;
      const key = el.dataset.socialKey;
      const newLinks = JSON.parse(JSON.stringify(blocks[i].links||[]));
      if(!newLinks[j]) newLinks[j]={};
      newLinks[j][key] = el.value;
      updateBlock(i, {links: newLinks}, true);
    });
  });

  panel.querySelectorAll('[data-social-remove]').forEach(btn => {
    btn.addEventListener('click', () => {
      const j = +btn.dataset.socialRemove;
      const newLinks = (blocks[i].links||[]).filter((_,k)=>k!==j);
      updateBlock(i, {links: newLinks});
    });
  });

  panel.querySelectorAll('[data-social-add]').forEach(btn => {
    btn.addEventListener('click', () => {
      const newLinks = [...(blocks[i].links||[]), {platform:'facebook', url:''}];
      updateBlock(i, {links: newLinks});
    });
  });

  // Image open gallery
  panel.querySelectorAll('[onclick*="ebOpenImgModal"]').forEach(() => {});

  // ── Column sub-block events ────────────────────────────
  panel.querySelectorAll('[data-col-add]').forEach(btn => {
    btn.addEventListener('click', () => {
      const ci = +btn.dataset.colAdd;
      const typeEl = panel.querySelector(`[data-col-add-type="${ci}"]`);
      const type = typeEl?.value || 'text';
      const newCols = JSON.parse(JSON.stringify(blocks[i].columns||[]));
      const cnt = colWidths(blocks[i].type).length;
      while(newCols.length<cnt) newCols.push([]);
      newCols[ci] = [...(newCols[ci]||[]), blockDefault(type)];
      pushHistory();
      updateBlock(i, {columns: newCols});
    });
  });

  panel.querySelectorAll('[data-col-remove]').forEach(btn => {
    btn.addEventListener('click', e => {
      e.stopPropagation();
      const [ci, cbi] = btn.dataset.colRemove.split('-').map(Number);
      const newCols = JSON.parse(JSON.stringify(blocks[i].columns||[]));
      newCols[ci] = (newCols[ci]||[]).filter((_,k)=>k!==cbi);
      pushHistory();
      updateBlock(i, {columns: newCols});
    });
  });

  panel.querySelectorAll('[data-col-move]').forEach(btn => {
    btn.addEventListener('click', e => {
      e.stopPropagation();
      if(btn.disabled) return;
      const parts = btn.dataset.colMove.split('-');
      const ci = +parts[0], cbi = +parts[1], dir = parts[2];
      const newCols = JSON.parse(JSON.stringify(blocks[i].columns||[]));
      const col = newCols[ci]||[];
      const target = dir==='up' ? cbi-1 : cbi+1;
      if(target<0||target>=col.length) return;
      [col[cbi], col[target]] = [col[target], col[cbi]];
      newCols[ci] = col;
      pushHistory();
      updateBlock(i, {columns: newCols});
    });
  });

  panel.querySelectorAll('.eb-col-block-row').forEach(row => {
    row.addEventListener('click', e => {
      if(e.target.closest('[data-col-remove]')) return;
      const ci = +row.dataset.col;
      const cbi = +row.dataset.cbi;
      const spEl = document.getElementById(`eb-col-sp-${ci}-${cbi}`);
      if(!spEl) return;
      const isOpen = spEl.style.display !== 'none';
      panel.querySelectorAll('.eb-col-subprops').forEach(el=>el.style.display='none');
      if(!isOpen) {
        const sb = (blocks[i].columns||[])[ci]?.[cbi];
        if(sb) {
          spEl.innerHTML = buildSubBlockPropsHtml(sb, i, ci, cbi);
          spEl.style.display = '';
          attachSubBlockPropEvents(spEl, i, ci, cbi);
        }
      }
    });
  });
}

/* ═══════════════════════════════════════════════════════════
   SUB-BLOCK HELPERS (for columns)
═══════════════════════════════════════════════════════════ */
function updateSubBlock(blockI, ci, cbi, props) {
  if(blockI<0||blockI>=blocks.length) return;
  const now = Date.now();
  if(now - _lastHistoryPush > 800) { pushHistory(); _lastHistoryPush = now; }
  const newCols = JSON.parse(JSON.stringify(blocks[blockI].columns||[]));
  if(!newCols[ci]||!newCols[ci][cbi]) return;
  Object.assign(newCols[ci][cbi], props);
  blocks[blockI].columns = newCols;
  renderCanvas();
  sync();
  markDirty();
}

function buildSubBlockPropsHtml(sb, blockI, ci, cbi) {
  const e2 = v => String(v??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  const a2 = v => String(v??'').replace(/"/g,'&quot;');
  const info = BLOCK_TYPES[sb.type]||{icon:'?',label:sb.type};
  let h = `<div style="font-size:9px;font-weight:700;color:#166534;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">${info.icon} ${info.label}</div>`;
  const g = (lbl,cnt) => `<div class="eb-props-group" style="margin-bottom:6px;"><label style="font-size:10.5px;">${lbl}</label>${cnt}</div>`;
  const ti = (k,v,ph='') => `<input type="text" data-subkey="${k}" value="${a2(v)}" placeholder="${a2(ph)}" style="width:100%;padding:4px 6px;border:1px solid #d1d5db;border-radius:4px;font-size:11.5px;box-sizing:border-box;">`;
  const ni = (k,v,mn,mx) => `<input type="number" data-subkey="${k}" value="${+v||0}" min="${mn}" max="${mx}" style="width:100%;padding:4px 6px;border:1px solid #d1d5db;border-radius:4px;font-size:11.5px;box-sizing:border-box;">`;
  const subRichBar = `<div class="eb-rich-bar">
    <button type="button" class="eb-rich-btn" data-subrich-tag="b" title="Bold"><b>B</b></button>
    <button type="button" class="eb-rich-btn" data-subrich-tag="i" title="Italic"><i>I</i></button>
    <button type="button" class="eb-rich-btn" data-subrich-tag="u" title="Subliniat"><u>U</u></button>
    <button type="button" class="eb-rich-btn" data-subrich-tag="s" title="Tăiat"><s>S</s></button>
    <button type="button" class="eb-rich-btn" data-subrich-link title="Link">🔗</button>
  </div>`;
  const SB_FONTS = [
    ['','Implicit (Arial)'],['arial','Arial'],['helvetica','Helvetica'],['verdana','Verdana'],
    ['tahoma','Tahoma'],['trebuchet','Trebuchet MS'],['georgia','Georgia'],['times','Times New Roman'],
    ['palatino','Palatino'],['courier','Courier New'],['lucida','Lucida Sans'],
  ];
  const subFontSelect = (val) => `<select data-subkey="font_family" style="width:100%;padding:4px 6px;border:1px solid #d1d5db;border-radius:4px;font-size:11.5px;box-sizing:border-box;">${SB_FONTS.map(([k,l])=>`<option value="${k}" ${(val||'')===k?'selected':''}>${l}</option>`).join('')}</select>`;
  if(sb.type==='text'||sb.type==='header') {
    h += g('Conținut', subRichBar + `<textarea data-subkey="content" rows="3" style="width:100%;padding:4px 6px;border:1px solid #d1d5db;border-radius:4px;font-size:11.5px;box-sizing:border-box;resize:vertical;">${e2(sb.content||'')}</textarea>`);
    h += g('Font', subFontSelect(sb.font_family||''));
    h += g('Mărime text (px)', ni('font_size', sb.font_size||(sb.type==='header'?24:14), 8, 72));
    h += g('Culoare text', ti('text_color', sb.text_color||'#333333', '#333333'));
    h += g('Fundal', ti('background', sb.background||'#ffffff', '#ffffff'));
  } else if(sb.type==='image') {
    h += g('URL imagine', `<div class="eb-image-row">${ti('image_url', sb.image_url||'', 'https://...')}<button type="button" class="btn btn-secondary" data-subimg-pick style="padding:4px 7px;font-size:11px;">📁</button></div>`);
    const sw = +(sb.width||100);
    h += g('Lățime (%)', `<div style="display:flex;align-items:center;gap:6px;"><input type="range" data-subkey="width" value="${sw}" min="10" max="100" style="flex:1;"><span data-subwidth-label style="font-size:11px;color:#6b7280;min-width:28px;text-align:right;">${sw}%</span></div>`);
    h += g('Rotunjire colțuri (px)', ni('border_radius', sb.border_radius||0, 0, 80));
    h += g('Fundal', ti('background', sb.background||'#ffffff', '#ffffff'));
    h += g('Link (opțional)', ti('link_url', sb.link_url||'', 'https://...'));
    h += g('Aliniere', `<div class="eb-align-row" style="margin-top:2px;">
      <button type="button" class="eb-rich-btn" data-subalign="left"  style="${(sb.align||'center')==='left'  ?'background:#1e6b50;color:#fff;border-color:#1e6b50;':''}" title="Stânga"><span>Stânga</span><span>↤</span></button>
      <button type="button" class="eb-rich-btn" data-subalign="center" style="${(sb.align||'center')==='center'?'background:#1e6b50;color:#fff;border-color:#1e6b50;':''}" title="Centru"><span>Centru</span><span>↔</span></button>
      <button type="button" class="eb-rich-btn" data-subalign="right" style="${(sb.align||'center')==='right' ?'background:#1e6b50;color:#fff;border-color:#1e6b50;':''}" title="Dreapta"><span>Dreapta</span><span>↦</span></button>
    </div>`);
  } else if(sb.type==='button') {
    h += g('Text buton', ti('label', sb.label||'Apasă'));
    h += g('URL', ti('url', sb.url||'', 'https://...'));
    h += g('Mărime text (px)', ni('font_size', sb.font_size||14, 10, 40));
    h += g('Mărime buton (spațiere px)', `
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
        <label style="font-size:10.5px;color:#6b7280;display:flex;align-items:center;gap:4px;">↕<input type="number" data-subkey="btn_pad_v" value="${+(sb.btn_pad_v??8)}" min="2" max="40" style="width:100%;padding:4px 6px;border:1px solid #d1d5db;border-radius:4px;font-size:11.5px;box-sizing:border-box;"></label>
        <label style="font-size:10.5px;color:#6b7280;display:flex;align-items:center;gap:4px;">↔<input type="number" data-subkey="btn_pad_h" value="${+(sb.btn_pad_h??18)}" min="4" max="80" style="width:100%;padding:4px 6px;border:1px solid #d1d5db;border-radius:4px;font-size:11.5px;box-sizing:border-box;"></label>
      </div>`);
    h += g('Rotunjire colțuri (px)', ni('border_radius', sb.border_radius||6, 0, 50));
    h += g('Culoare buton', ti('button_color', sb.button_color||'#2d7d5f', '#2d7d5f'));
    h += g('Culoare text', ti('text_color', sb.text_color||'#ffffff', '#ffffff'));
    h += g('Fundal', ti('background', sb.background||'#ffffff', '#ffffff'));
  } else if(sb.type==='spacer') {
    h += g('Înălțime (px)', ni('height', sb.height||16, 4, 200));
  } else if(sb.type==='divider') {
    h += g('Culoare linie', ti('color', sb.color||'#e5e7eb', '#e5e7eb'));
    h += g('Grosime (px)', ni('thickness', sb.thickness||1, 1, 10));
    h += g('Fundal', ti('background', sb.background||'#ffffff', '#ffffff'));
  }
  h += g('Margini (px)', `
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
      <label style="font-size:10.5px;color:#6b7280;display:flex;align-items:center;gap:4px;">↑ Sus<input type="number" data-subkey="padding_top" value="${+(sb.padding_top||0)}" min="0" max="120" style="width:100%;padding:4px 6px;border:1px solid #d1d5db;border-radius:4px;font-size:11.5px;box-sizing:border-box;"></label>
      <label style="font-size:10.5px;color:#6b7280;display:flex;align-items:center;gap:4px;">↓ Jos<input type="number" data-subkey="padding_bottom" value="${+(sb.padding_bottom||0)}" min="0" max="120" style="width:100%;padding:4px 6px;border:1px solid #d1d5db;border-radius:4px;font-size:11.5px;box-sizing:border-box;"></label>
      <label style="font-size:10.5px;color:#6b7280;display:flex;align-items:center;gap:4px;">← Stânga<input type="number" data-subkey="padding_left" value="${+(sb.padding_left||0)}" min="0" max="120" style="width:100%;padding:4px 6px;border:1px solid #d1d5db;border-radius:4px;font-size:11.5px;box-sizing:border-box;"></label>
      <label style="font-size:10.5px;color:#6b7280;display:flex;align-items:center;gap:4px;">Dreapta →<input type="number" data-subkey="padding_right" value="${+(sb.padding_right||0)}" min="0" max="120" style="width:100%;padding:4px 6px;border:1px solid #d1d5db;border-radius:4px;font-size:11.5px;box-sizing:border-box;"></label>
    </div>`);
  h += g('Vizibilitate', `
    <div style="display:flex;flex-direction:column;gap:3px;font-size:10.5px;color:#374151;">
      <label style="display:flex;align-items:center;gap:5px;cursor:pointer;"><input type="checkbox" data-subcheckkey="hide_desktop" ${sb.hide_desktop?'checked':''}> Ascunde pe desktop</label>
      <label style="display:flex;align-items:center;gap:5px;cursor:pointer;"><input type="checkbox" data-subcheckkey="hide_tablet" ${sb.hide_tablet?'checked':''}> Ascunde pe tabletă</label>
      <label style="display:flex;align-items:center;gap:5px;cursor:pointer;"><input type="checkbox" data-subcheckkey="hide_mobile" ${sb.hide_mobile?'checked':''}> Ascunde pe mobil</label>
    </div>`);
  return h;
}

function attachSubBlockPropEvents(el, blockI, ci, cbi) {
  el.querySelectorAll('[data-subkey]').forEach(inp => {
    inp.addEventListener('input', () => {
      const key = inp.dataset.subkey;
      let val = inp.tagName==='TEXTAREA' ? inp.value : (inp.type==='number' ? +inp.value : inp.value);
      updateSubBlock(blockI, ci, cbi, {[key]: val});
    });
  });

  // Sub-block visibility checkboxes
  el.querySelectorAll('[data-subcheckkey]').forEach(inp => {
    inp.addEventListener('change', () => {
      updateSubBlock(blockI, ci, cbi, {[inp.dataset.subcheckkey]: inp.checked ? 1 : 0});
    });
  });

  // Sub-block image width slider
  el.querySelectorAll('input[type=range][data-subkey="width"]').forEach(range => {
    const lbl = el.querySelector('[data-subwidth-label]');
    range.addEventListener('input', () => {
      const v = +range.value;
      if(lbl) lbl.textContent = v + '%';
      updateSubBlock(blockI, ci, cbi, {width: v});
    });
  });

  // Sub-block image alignment buttons
  el.querySelectorAll('[data-subalign]').forEach(btn => {
    btn.addEventListener('click', () => {
      const val = btn.dataset.subalign;
      el.querySelectorAll('[data-subalign]').forEach(b => { b.style.background=''; b.style.color=''; b.style.borderColor=''; });
      btn.style.background='#1e6b50'; btn.style.color='#fff'; btn.style.borderColor='#1e6b50';
      updateSubBlock(blockI, ci, cbi, {align: val});
    });
  });

  // Sub-block rich text B/I/U + Link buttons
  el.querySelectorAll('[data-subrich-tag]').forEach(btn => {
    btn.addEventListener('click', () => {
      const tag = btn.dataset.subrichTag;
      const ta = btn.closest('.eb-props-group')?.querySelector('textarea[data-subkey]');
      if(!ta) return;
      const start = ta.selectionStart, end = ta.selectionEnd;
      const sel = ta.value.slice(start, end);
      const open = `<${tag}>`, close = `</${tag}>`;
      if(ta.value.slice(start-open.length,start)===open && ta.value.slice(end,end+close.length)===close) {
        ta.value = ta.value.slice(0,start-open.length)+sel+ta.value.slice(end+close.length);
        ta.setSelectionRange(start-open.length, start-open.length+sel.length);
      } else {
        ta.setRangeText(open+sel+close, start, end, 'select');
      }
      ta.dispatchEvent(new Event('input'));
    });
  });

  el.querySelectorAll('[data-subrich-link]').forEach(btn => {
    btn.addEventListener('click', () => {
      const ta = btn.closest('.eb-props-group')?.querySelector('textarea[data-subkey]');
      if(!ta) return;
      const start = ta.selectionStart, end = ta.selectionEnd;
      const sel = ta.value.slice(start, end);
      const url = window.prompt('URL link:', 'https://');
      if(!url) return;
      ta.setRangeText(`<a href="${url}">${sel||url}</a>`, start, end, 'select');
      ta.dispatchEvent(new Event('input'));
    });
  });

  // Gallery picker for sub-block image
  el.querySelectorAll('[data-subimg-pick]').forEach(btn => {
    btn.addEventListener('click', () => {
      const urlInput = el.querySelector('input[data-subkey="image_url"]');
      ebOpenImgModal(-1, 'image_url', (url) => {
        updateSubBlock(blockI, ci, cbi, {image_url: url});
        if(urlInput) urlInput.value = url;
      });
    });
  });
}

/* ═══════════════════════════════════════════════════════════
   PALETTE DRAG
═══════════════════════════════════════════════════════════ */
document.querySelectorAll('.eb-block-btn[data-block-type]').forEach(btn => {
  btn.addEventListener('click', () => addBlock(btn.dataset.blockType, blocks.length-1));
  btn.addEventListener('dragstart', e => {
    dragState = {source:'palette', type: btn.dataset.blockType};
    e.dataTransfer.effectAllowed = 'copy';
    document.getElementById('eb-canvas-inner')?.classList.add('dragging-active');
  });
  btn.addEventListener('dragend', () => {
    dragState = null;
    document.getElementById('eb-canvas-inner')?.classList.remove('dragging-active');
  });
});

/* ═══════════════════════════════════════════════════════════
   PREVIEW MODE
═══════════════════════════════════════════════════════════ */
document.querySelectorAll('.eb-preview-tabs button[data-preview]').forEach(btn => {
  btn.addEventListener('click', () => {
    previewMode = btn.dataset.preview;
    document.querySelectorAll('.eb-preview-tabs button').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    applyPreviewMode();
  });
});

function applyPreviewMode() {
  const canvasScroll  = document.getElementById('eb-canvas-scroll');
  const previewPane   = document.getElementById('eb-preview-pane');
  const canvasInner   = document.getElementById('eb-canvas-inner');

  if(previewMode === 'edit') {
    if(canvasScroll) canvasScroll.style.display = '';
    if(previewPane)  previewPane.style.display = 'none';
  } else {
    if(canvasScroll) canvasScroll.style.display = 'none';
    if(previewPane)  previewPane.style.display = '';
    loadPreview(previewMode === 'mobile' ? 375 : 960);
  }
}

function loadPreview(width) {
  const loading = document.getElementById('eb-preview-loading');
  const iframe  = document.getElementById('eb-preview-iframe');
  const frame   = document.getElementById('eb-preview-frame');

  if(loading) loading.style.display = '';
  if(iframe)  iframe.style.display  = 'none';
  if(frame) {
    frame.style.width = (width + 2) + 'px';
    frame.classList.toggle('mobile-preview', width <= 400);
  }

  const subject = document.getElementById('eb-subject-input')?.value || '';

  fetch('/admin/emails/builder/preview', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body: 'blocks_json='+encodeURIComponent(JSON.stringify(blocks))+'&subject='+encodeURIComponent(subject),
  })
  .then(r=>r.json())
  .then(d => {
    if(d.ok && iframe) {
      iframe.style.width  = width+'px';
      iframe.style.height = '600px';
      iframe.srcdoc = d.html;
      iframe.onload = () => {
        const h = iframe.contentDocument?.documentElement?.scrollHeight;
        if(h) iframe.style.height = h+10+'px';
      };
      if(loading) loading.style.display = 'none';
      iframe.style.display = '';
    }
  })
  .catch(() => { if(loading) loading.textContent = 'Eroare la generarea preview.'; });
}

/* ═══════════════════════════════════════════════════════════
   STARTER TEMPLATES
═══════════════════════════════════════════════════════════ */
window.ebShowStarterModal = function() {
  document.getElementById('eb-starter-modal').style.display='flex';
};
window.ebCloseStarterModal = function() {
  document.getElementById('eb-starter-modal').style.display='none';
};
window.ebApplyStarter = function(preset) {
  const starters = {
    blank: [
      {type:'header', content:'Titlul email-ului', align:'center', font_size:30, font_size_mobile:22, background:'#2d7d5f', text_color:'#ffffff'},
    ],
    welcome: [
      {type:'header', content:'Bun venit! 👋', align:'center', font_size:30, font_size_mobile:22, background:'#2d7d5f', text_color:'#ffffff'},
      {type:'spacer', height:16, background:'#ffffff'},
      {type:'text', content:'Îți mulțumim că te-ai abonat la newsletter-ul nostru. Suntem bucuroși să te avem alături!', align:'center', font_size:16, font_size_mobile:14, background:'#ffffff', text_color:'#333333'},
      {type:'spacer', height:8, background:'#ffffff'},
      {type:'button', label:'Descoperă produsele noastre', url:'', align:'center', background:'#ffffff', button_color:'#2d7d5f', text_color:'#ffffff', border_radius:6},
      {type:'spacer', height:16, background:'#ffffff'},
    ],
    promo: [
      {type:'header', content:'Ofertă specială 🎁', align:'center', font_size:34, font_size_mobile:24, background:'#2d7d5f', text_color:'#ffffff'},
      {type:'image', image_url:'', alt:'Banner promoție', align:'center', background:'#ffffff', width:100},
      {type:'spacer', height:12, background:'#ffffff'},
      {type:'text', content:'Nu rata această ofertă exclusivă! Comandă acum și beneficiezi de reducere.', align:'center', font_size:16, font_size_mobile:14, background:'#ffffff', text_color:'#333333'},
      {type:'spacer', height:8, background:'#ffffff'},
      {type:'button', label:'Cumpără acum', url:'', align:'center', background:'#ffffff', button_color:'#dc2626', text_color:'#ffffff', border_radius:6},
      {type:'spacer', height:16, background:'#ffffff'},
    ],
    newsletter: [
      {type:'header', content:'Newsletter lunar', align:'center', font_size:28, font_size_mobile:22, background:'#2d7d5f', text_color:'#ffffff'},
      {type:'spacer', height:16, background:'#ffffff'},
      {type:'text', content:'Salutare!\n\nIată cele mai importante noutăți ale lunii...', align:'left', font_size:16, font_size_mobile:14, background:'#ffffff', text_color:'#333333'},
      {type:'divider', background:'#ffffff', color:'#e5e7eb', width:80, thickness:1},
      {type:'text', content:'Titlu articol\n\nDescriere scurtă a articolului sau produsului. Click pentru a afla mai multe.', align:'left', font_size:15, font_size_mobile:13, background:'#f9fafb', text_color:'#374151'},
      {type:'button', label:'Citește mai mult', url:'', align:'left', background:'#f9fafb', button_color:'#2d7d5f', text_color:'#ffffff', border_radius:5},
      {type:'divider', background:'#ffffff', color:'#e5e7eb', width:80, thickness:1},
      {type:'spacer', height:16, background:'#ffffff'},
    ],
    order: [
      {type:'header', content:'Confirmare comandă', align:'center', font_size:28, font_size_mobile:22, background:'#2d7d5f', text_color:'#ffffff'},
      {type:'spacer', height:16, background:'#ffffff'},
      {type:'text', content:'Dragă {{customer_name}},\n\nComanda ta #{{order_number}} a fost primită cu succes. Mulțumim!', align:'left', font_size:16, font_size_mobile:14, background:'#ffffff', text_color:'#333333'},
      {type:'divider', background:'#ffffff', color:'#e5e7eb', width:100, thickness:1},
      {type:'text', content:'{{order_items_html}}', align:'left', font_size:14, font_size_mobile:13, background:'#f9fafb', text_color:'#374151'},
      {type:'divider', background:'#ffffff', color:'#e5e7eb', width:100, thickness:1},
      {type:'text', content:'Total: {{order_total}}', align:'right', font_size:16, font_size_mobile:15, background:'#ffffff', text_color:'#111827'},
      {type:'spacer', height:12, background:'#ffffff'},
      {type:'button', label:'Vezi comanda', url:'{{order_action_url}}', align:'center', background:'#ffffff', button_color:'#2d7d5f', text_color:'#ffffff', border_radius:6},
      {type:'spacer', height:16, background:'#ffffff'},
    ],
    simple: [
      {type:'header', content:'Titlu email', align:'center', font_size:30, font_size_mobile:22, background:'#2d7d5f', text_color:'#ffffff'},
      {type:'spacer', height:16, background:'#ffffff'},
      {type:'text', content:'Scrie conținutul tău principal aici.', align:'left', font_size:16, font_size_mobile:14, background:'#ffffff', text_color:'#333333'},
      {type:'divider', background:'#ffffff', color:'#e5e7eb', width:80, thickness:1},
      {type:'text', content:'Informații suplimentare sau footer text.', align:'center', font_size:13, font_size_mobile:12, background:'#f9fafb', text_color:'#6b7280'},
      {type:'spacer', height:16, background:'#f9fafb'},
    ],
  };
  const tpl = starters[preset];
  if(!tpl) return;
  pushHistory();
  blocks = JSON.parse(JSON.stringify(tpl));
  sel = -1;
  ebCloseStarterModal();
  render(); sync(); markDirty();
};

/* ═══════════════════════════════════════════════════════════
   IMAGE PICKER
═══════════════════════════════════════════════════════════ */
let imgPickTarget = -1;
let imgPickKey = 'image_url';
let imgPickCb = null;
window.ebOpenImgModal = function(blockIndex, propKey, callback) {
  imgPickTarget = blockIndex;
  imgPickKey = propKey || 'image_url';
  imgPickCb = callback || null;
  const inp = document.getElementById('eb-img-url-input');
  if(inp) inp.value = (blockIndex>=0 ? blocks[blockIndex]?.[imgPickKey] : '') || '';
  const srch = document.getElementById('eb-gallery-search');
  if(srch) srch.value = '';
  document.getElementById('eb-gallery-grid')?.querySelectorAll('.eb-gallery-img').forEach(el=>el.dataset.searchMatch='1');
  document.getElementById('eb-img-modal').style.display='flex';
  ebGalleryPage(0);
};

const GALLERY_PAGE_SIZE = 12;
let galleryPage = 0;
window.ebGalleryPage = function(page) {
  const grid = document.getElementById('eb-gallery-grid');
  if(!grid) return;
  const allImgs = [...grid.querySelectorAll('.eb-gallery-img')];
  const imgs = allImgs.filter(el => el.dataset.searchMatch !== '0');
  const total = imgs.length;
  const pages = Math.ceil(total / GALLERY_PAGE_SIZE) || 1;
  galleryPage = Math.max(0, Math.min(page, pages-1));
  allImgs.forEach(el => el.style.display = 'none');
  imgs.slice(galleryPage*GALLERY_PAGE_SIZE, (galleryPage+1)*GALLERY_PAGE_SIZE).forEach(el => el.style.display = '');
  const pager = document.getElementById('eb-gallery-pager');
  if(pager) {
    if(total === 0) { pager.style.display='flex'; pager.innerHTML='<span style="color:#9ca3af;font-size:12px;">Nicio imagine găsită.</span>'; return; }
    if(pages <= 1) { pager.style.display='none'; return; }
    pager.style.display = 'flex';
    pager.innerHTML = `<button type="button" onclick="ebGalleryPage(${galleryPage-1})" ${galleryPage===0?'disabled':''} style="padding:4px 10px;font-size:12px;border:1px solid #d1d5db;border-radius:4px;background:#fff;cursor:pointer;">← Prev</button>
      <span style="font-size:12px;color:#6b7280;align-self:center;">Pagina ${galleryPage+1} / ${pages}</span>
      <button type="button" onclick="ebGalleryPage(${galleryPage+1})" ${galleryPage===pages-1?'disabled':''} style="padding:4px 10px;font-size:12px;border:1px solid #d1d5db;border-radius:4px;background:#fff;cursor:pointer;">Next →</button>`;
  }
};
window.ebCloseImgModal = function() {
  document.getElementById('eb-img-modal').style.display='none';
  imgPickTarget=-1; imgPickCb=null;
};

// Gallery search
document.getElementById('eb-gallery-search')?.addEventListener('input', () => {
  const q = (document.getElementById('eb-gallery-search').value||'').toLowerCase();
  document.getElementById('eb-gallery-grid')?.querySelectorAll('.eb-gallery-img').forEach(el => {
    const match = !q || (el.title||el.dataset.url||'').toLowerCase().includes(q);
    el.dataset.searchMatch = match ? '1' : '0';
  });
  ebGalleryPage(0);
});

// Image picker modal — don't close when mousedown was inside
{
  let imgModalMdInside = false;
  const imgOvl = document.getElementById('eb-img-modal');
  imgOvl?.addEventListener('mousedown', e => { imgModalMdInside = e.target !== imgOvl; });
  imgOvl?.addEventListener('click', e => { if(e.target === imgOvl && !imgModalMdInside) ebCloseImgModal(); });
}

window.ebOpenFullPreview = function() {
  document.getElementById('eb-fullpreview-modal').style.display='flex';
  const loading = document.getElementById('eb-fprev-loading');
  const iframe  = document.getElementById('eb-fprev-iframe');
  if(loading) loading.style.display='';
  if(iframe)  iframe.style.display='none';
  ebFullPreviewLoad(960);
};
window.ebCloseFullPreview = function() {
  document.getElementById('eb-fullpreview-modal').style.display='none';
};
{
  let fpMdInside = false;
  const fpOvl = document.getElementById('eb-fullpreview-modal');
  fpOvl?.addEventListener('mousedown', e => { fpMdInside = e.target !== fpOvl; });
  fpOvl?.addEventListener('click', e => { if(e.target === fpOvl && !fpMdInside) ebCloseFullPreview(); });
}
window.ebFullPreviewSetWidth = function(w) {
  document.querySelectorAll('#fprev-desktop,#fprev-mobile').forEach(b=>b.classList.remove('active'));
  document.getElementById(w===375?'fprev-mobile':'fprev-desktop')?.classList.add('active');
  const iframe = document.getElementById('eb-fprev-iframe');
  if(iframe) { iframe.style.width=w+'px'; iframe.style.maxWidth='100%'; }
  const wrap = document.querySelector('.eb-preview-frame, #eb-fprev-iframe')?.closest?.('.eb-modal-body');
  if(wrap) wrap.style.background = w<=400 ? '#f3f4f6' : '';
};
function ebFullPreviewLoad(width) {
  const loading = document.getElementById('eb-fprev-loading');
  const iframe  = document.getElementById('eb-fprev-iframe');
  const subject = document.getElementById('eb-subject-input')?.value||'';
  fetch('/admin/emails/builder/preview',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'blocks_json='+encodeURIComponent(JSON.stringify(blocks))+'&subject='+encodeURIComponent(subject)})
  .then(r=>r.json()).then(d=>{
    if(d.ok&&iframe){
      iframe.style.cssText=`width:${width}px;max-width:100%;min-height:400px;border:none;display:block;border-radius:8px;`;
      iframe.srcdoc=d.html;
      iframe.onload=()=>{const h=iframe.contentDocument?.documentElement?.scrollHeight;if(h)iframe.style.height=h+10+'px';};
      if(loading) loading.style.display='none';
      iframe.style.display='';
    }
  }).catch(()=>{if(loading)loading.textContent='Eroare la generarea preview.';});
}
window.ebPickGalleryImg = function(url) {
  const inp = document.getElementById('eb-img-url-input');
  if(inp) inp.value = url;
};
window.ebConfirmImgUrl = function() {
  const url = document.getElementById('eb-img-url-input')?.value?.trim()||'';
  if(imgPickCb) { imgPickCb(url); imgPickCb=null; }
  else if(imgPickTarget >= 0) updateBlock(imgPickTarget, {[imgPickKey]: url});
  ebCloseImgModal();
};

/* ═══════════════════════════════════════════════════════════
   SETTINGS PANEL WIRING
═══════════════════════════════════════════════════════════ */
const setName    = document.getElementById('eb-set-name');
const setSubject = document.getElementById('eb-set-subject');
const setActive  = document.getElementById('eb-set-active');
const setRecip   = document.getElementById('eb-set-recip-mode');
const setAdminR  = document.getElementById('eb-set-admin-recip');

if(setName) setName.addEventListener('input', () => {
  const nameInp = document.getElementById('eb-name-input');
  if(nameInp) nameInp.value = setName.value;
  const titleDisplay = document.getElementById('eb-title-display');
  if(titleDisplay) titleDisplay.textContent = setName.value;
  markDirty();
});
if(setSubject) setSubject.addEventListener('input', () => {
  const subj = document.getElementById('eb-subject-input');
  if(subj) subj.value = setSubject.value;
  markDirty();
});
if(setActive) setActive.addEventListener('change', () => {
  const activeInp = document.getElementById('eb-active-input');
  if(activeInp) activeInp.value = setActive.checked ? '1' : '0';
  // Update name attribute based on checked state (server reads presence of field)
  if(setActive.checked) activeInp.name = 'builder_is_active';
  else activeInp.name = 'builder_is_active_off'; // won't be read
  markDirty();
});
if(setRecip) setRecip.addEventListener('change', () => {
  const inp = document.getElementById('eb-recip-mode-input');
  if(inp) inp.value = setRecip.value;
  const wrap = document.getElementById('eb-admin-recip-wrap');
  if(wrap) wrap.style.display = ['admin','client_admin'].includes(setRecip.value) ? '' : 'none';
  markDirty();
});
if(setAdminR) setAdminR.addEventListener('input', () => {
  const inp = document.getElementById('eb-recip-admin-input');
  if(inp) inp.value = setAdminR.value;
  markDirty();
});

// Token copy buttons
document.querySelectorAll('.eb-token-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    navigator.clipboard?.writeText(btn.dataset.token).then(() => {
      const orig = btn.textContent;
      btn.textContent = '✓ Copiat!';
      setTimeout(()=>btn.textContent=orig, 1500);
    });
  });
});

/* ═══════════════════════════════════════════════════════════
   SAVE
═══════════════════════════════════════════════════════════ */
document.getElementById('btn-save')?.addEventListener('click', () => {
  sync();
  document.getElementById('eb-form')?.submit();
  clearDirty();
});
document.getElementById('btn-fullpreview')?.addEventListener('click', ebOpenFullPreview);

// Warn on unsaved changes
window.addEventListener('beforeunload', e => {
  if(dirty) { e.preventDefault(); e.returnValue=''; }
});

/* ═══════════════════════════════════════════════════════════
   KEYBOARD SHORTCUTS
═══════════════════════════════════════════════════════════ */
document.addEventListener('keydown', e => {
  const tag = document.activeElement?.tagName;
  if(tag==='INPUT'||tag==='TEXTAREA'||tag==='SELECT') return;
  if((e.ctrlKey||e.metaKey) && !e.shiftKey && e.key==='z') { e.preventDefault(); undo(); }
  if((e.ctrlKey||e.metaKey) && (e.key==='y' || (e.shiftKey && e.key==='z'))) { e.preventDefault(); redo(); }
  if(e.key==='Delete'||e.key==='Backspace') {
    if(sel>=0) { removeBlock(sel); }
  }
  if((e.ctrlKey||e.metaKey) && e.key==='s') { e.preventDefault(); document.getElementById('btn-save')?.click(); }
});

/* ═══════════════════════════════════════════════════════════
   UNDO / REDO BUTTONS
═══════════════════════════════════════════════════════════ */
document.getElementById('btn-undo')?.addEventListener('click', undo);
document.getElementById('btn-redo')?.addEventListener('click', redo);

/* ═══════════════════════════════════════════════════════════
   BACK LINK — warn if dirty
═══════════════════════════════════════════════════════════ */
document.getElementById('eb-back-link')?.addEventListener('click', e => {
  if(dirty && !confirm('Ai modificări nesalvate. Ești sigur că vrei să pleci?')) e.preventDefault();
});

/* ═══════════════════════════════════════════════════════════
   CAMPAIGN WIZARD
═══════════════════════════════════════════════════════════ */
<?php if ($bType === 'campaign'): ?>
let ebWizCurrentStep = 1;
let ebWizSelectedListIds = <?= json_encode($bListIds) ?>;

function ebWizUpdateListSummary() {
  const total = [...document.querySelectorAll('.eb-wiz-list-card.selected')]
    .reduce((s, el) => s + parseInt(el.dataset.count || 0), 0);
  const el = document.getElementById('eb-wiz-list-count-summary');
  if(el) el.textContent = ebWizSelectedListIds.length > 0
    ? `${ebWizSelectedListIds.length} list${ebWizSelectedListIds.length > 1 ? 'e' : 'ă'} selectat${ebWizSelectedListIds.length > 1 ? 'e' : 'ă'} · ~${total} abonați`
    : '';
}

window.ebWizGo = function(step) {
  ebWizCurrentStep = step;
  // Show/hide body panels
  const body = document.getElementById('eb-wrap')?.querySelector('.eb-body');
  if(body) body.style.display = step === 1 ? '' : 'none';
  [2,3,4].forEach(s => {
    const p = document.getElementById('eb-wiz-panel-'+s);
    if(p) p.style.display = s === step ? '' : 'none';
  });
  // Update step buttons
  document.querySelectorAll('.eb-wizard-step[data-wiz-step]').forEach(btn => {
    const s = parseInt(btn.dataset.wizStep);
    btn.classList.toggle('active', s === step);
    btn.classList.toggle('done', s < step);
  });
  if(step === 2) ebWizUpdateListSummary();
  if(step === 4) ebWizLoadPreview(600);
};

window.ebWizToggleList = function(id) {
  const idx = ebWizSelectedListIds.indexOf(id);
  if(idx === -1) ebWizSelectedListIds.push(id);
  else ebWizSelectedListIds.splice(idx, 1);
  document.getElementById('eb-list-ids-input').value = ebWizSelectedListIds.join(',');
  document.querySelectorAll('.eb-wiz-list-card').forEach(card => {
    card.classList.toggle('selected', ebWizSelectedListIds.includes(parseInt(card.dataset.listId)));
  });
  ebWizUpdateListSummary();
};

window.ebWizSaveAndGo = function(nextStep) {
  const nameEl = document.getElementById('eb-wiz-name');
  const subjEl = document.getElementById('eb-wiz-subject');
  if(nameEl) { document.getElementById('eb-name-input').value = nameEl.value; document.getElementById('eb-title-display').textContent = nameEl.value; }
  if(subjEl) document.getElementById('eb-subject-input').value = subjEl.value;
  document.getElementById('eb-list-ids-input').value = ebWizSelectedListIds.join(',');
  sync();
  const form = document.getElementById('eb-form');
  if(!form) { ebWizGo(nextStep); return; }
  const fd = new FormData(form);
  fetch('/admin/emails/builder', {method:'POST', body: new URLSearchParams(fd)})
    .then(() => { clearDirty(); ebWizGo(nextStep); })
    .catch(() => ebWizGo(nextStep));
};

window.ebWizSyncBeforeAction = function() {
  // Save the campaign data first via AJAX before form submission
  const nameEl = document.getElementById('eb-wiz-name');
  const subjEl = document.getElementById('eb-wiz-subject');
  if(nameEl) document.getElementById('eb-name-input').value = nameEl.value;
  if(subjEl) document.getElementById('eb-subject-input').value = subjEl.value;
  document.getElementById('eb-list-ids-input').value = ebWizSelectedListIds.join(',');
  sync();
  // Save via AJAX then let the form submit
  const form = document.getElementById('eb-form');
  if(form) {
    const fd = new FormData(form);
    fetch('/admin/emails/builder', {method:'POST', body: new URLSearchParams(fd)}).catch(()=>{});
  }
  clearDirty();
  return true;
};

window.ebWizSyncDraftForm = function() {
  sync();
  const nameEl = document.getElementById('eb-wiz-name');
  const subjEl = document.getElementById('eb-wiz-subject');
  if(nameEl) document.getElementById('eb-wiz-save-draft-name').value = nameEl.value;
  if(subjEl) document.getElementById('eb-wiz-save-draft-subject').value = subjEl.value;
  document.getElementById('eb-wiz-save-draft-list').value = ebWizSelectedListIds.join(',');
  document.getElementById('eb-wiz-save-draft-blocks').value = document.getElementById('eb-blocks-input')?.value || '[]';
};

window.ebWizLoadPreview = function(width) {
  const loading = document.getElementById('eb-wiz-prev-loading');
  const iframe  = document.getElementById('eb-wiz-prev-iframe');
  if(!iframe) return;
  if(loading) loading.style.display = '';
  iframe.style.display = 'none';
  const subject = document.getElementById('eb-subject-input')?.value || '';
  sync();
  fetch('/admin/emails/builder/preview', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'blocks_json='+encodeURIComponent(JSON.stringify(blocks))+'&subject='+encodeURIComponent(subject)
  }).then(r=>r.json()).then(d=>{
    if(d.ok && iframe) {
      iframe.style.cssText = `width:${width}px;max-width:100%;min-height:300px;border:none;display:block;`;
      iframe.srcdoc = d.html;
      iframe.onload = () => { const h=iframe.contentDocument?.documentElement?.scrollHeight; if(h) iframe.style.height=h+10+'px'; };
      if(loading) loading.style.display='none';
    }
  }).catch(()=>{ if(loading) loading.textContent='Eroare la generarea preview.'; });
  // Update tab buttons
  ['desktop','mobile'].forEach(t => {
    const btn = document.getElementById('fpwiz-'+t);
    if(btn) btn.classList.toggle('active', (t==='desktop'&&width===600)||(t==='mobile'&&width===375));
  });
};

window.ebWizPreviewWidth = function(width) {
  ebWizLoadPreview(width);
  const iframe = document.getElementById('eb-wiz-prev-iframe');
  if(iframe) iframe.style.width = width+'px';
};
<?php endif; ?>

/* ═══════════════════════════════════════════════════════════
   INIT
═══════════════════════════════════════════════════════════ */
(function ebFitHeight() {
  const el = document.getElementById('eb-wrap');
  if (!el) return;
  // Note: we deliberately do NOT lock document/body overflow here — doing so froze
  // the admin sidebar menu. Scroll-chaining from the inner panels to the page is
  // prevented with `overscroll-behavior:contain` on the panels themselves (CSS),
  // so the palette/canvas/props scroll internally without dragging the page.
  const fit = () => {
    el.style.height = (window.innerHeight - (el.getBoundingClientRect().top)) + 'px';
  };
  fit();
  window.addEventListener('resize', fit);
})();

render();
sync();
<?php if ($bShowStarter): ?>
setTimeout(() => ebShowStarterModal(), 300);
<?php endif; ?>

})(); // end IIFE
</script>
