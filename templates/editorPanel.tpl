<div id="scholar9ReviewPanel" class="pkp_workflow_stage_panel" style="margin-top:1.5rem;border:1px solid #d1d5db;border-radius:8px;overflow:hidden;">

    {* ── Header ── *}
    <div style="background:#1e3a5f;color:#fff;padding:12px 16px;display:flex;align-items:center;justify-content:space-between;">
        <span style="font-weight:600;font-size:14px;display:flex;align-items:center;gap:8px;">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M9 12l2 2 4-4M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            Scholar9 Transparent Peer Review
        </span>
        {if $s9_record && $s9_record.s9_review_url}
            <a href="{$s9_record.s9_review_url|escape}" target="_blank" rel="noopener noreferrer"
               style="color:#93c5fd;font-size:12px;text-decoration:none;">
                Open in Scholar9 ↗
            </a>
        {/if}
    </div>

    <div style="padding:16px;background:#fff;" id="s9PanelBody">

        {if !$s9_configured}
            <p style="color:#b91c1c;font-size:13px;">
                Scholar9 is not configured. Please go to
                <strong>Plugin Settings</strong> and enter your API credentials.
            </p>
        {elseif !$s9_record || empty($s9_record.s9_paper_id)}

            {* ── Not yet sent ── *}
            <p style="color:#6b7280;font-size:13px;margin-bottom:12px;">
                This submission has not been sent to Scholar9 for peer review yet.
            </p>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <input type="text" id="s9ManuscriptUrl" placeholder="Manuscript file URL (leave blank to use OJS file)"
                    style="flex:1;border:1px solid #d1d5db;border-radius:6px;padding:7px 10px;font-size:13px;" />
                <button id="s9SendBtn" onclick="s9Create()"
                    style="background:#1e3a5f;color:#fff;border:none;border-radius:6px;padding:8px 18px;font-size:13px;cursor:pointer;font-weight:600;">
                    Send to Scholar9
                </button>
            </div>

        {else}

            {* ── Status row ── *}
            <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:14px;">
                <div>
                    <span style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;">Status</span><br>
                    <span id="s9StatusBadge" style="display:inline-block;margin-top:4px;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;
                        background:{if $s9_record.status == 'decision_returned'}#d1fae5{elseif $s9_record.status == 'under_review'}#dbeafe{elseif $s9_record.status == 'reviewer_declined' || $s9_record.status == 'cancelled'}#fee2e2{else}#fef3c7{/if};
                        color:{if $s9_record.status == 'decision_returned'}#065f46{elseif $s9_record.status == 'under_review'}#1e40af{elseif $s9_record.status == 'reviewer_declined' || $s9_record.status == 'cancelled'}#991b1b{else}#92400e{/if};">
                        {$s9_record.status|replace:'_':' '|ucwords}
                    </span>
                </div>
                {if $s9_record.reviewer_name}
                    <div>
                        <span style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;">Reviewer</span><br>
                        <span style="font-size:13px;font-weight:500;color:#111827;">{$s9_record.reviewer_name|escape}</span>
                    </div>
                {/if}
                {if $s9_record.score}
                    <div>
                        <span style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;">Score</span><br>
                        <span style="font-size:18px;font-weight:700;color:#1e3a5f;">{$s9_record.score|string_format:"%.1f"}</span>
                        <span style="font-size:11px;color:#6b7280;">/5</span>
                    </div>
                {/if}
                {if $s9_record.recommendation}
                    <div>
                        <span style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;">Recommendation</span><br>
                        <span style="font-size:13px;font-weight:600;color:#1e3a5f;">{$s9_record.recommendation|ucfirst|escape}</span>
                    </div>
                {/if}
            </div>

            {* ── AI Summary ── *}
            {if $s9_record.ai_summary}
                <div style="background:#f0f9ff;border-left:3px solid #0ea5e9;padding:10px 14px;border-radius:0 6px 6px 0;margin-bottom:14px;">
                    <div style="font-size:11px;font-weight:600;color:#0369a1;margin-bottom:4px;text-transform:uppercase;letter-spacing:.05em;">
                        AI Analysis
                    </div>
                    <p style="font-size:13px;color:#0c4a6e;margin:0;">{$s9_record.ai_summary|escape}</p>
                </div>
            {/if}

            {* ── Reviewer comment ── *}
            {if $s9_record.reviewer_comment}
                <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:10px 14px;margin-bottom:14px;">
                    <div style="font-size:11px;font-weight:600;color:#374151;margin-bottom:4px;text-transform:uppercase;letter-spacing:.05em;">
                        Reviewer Comment
                    </div>
                    <p style="font-size:13px;color:#374151;margin:0;">{$s9_record.reviewer_comment|escape|nl2br}</p>
                </div>
            {/if}

            {* ── Message thread ── *}
            {if $s9_record.messages_cache}
                <div style="border:1px solid #e5e7eb;border-radius:6px;margin-bottom:14px;overflow:hidden;">
                    <div style="padding:8px 14px;background:#f9fafb;font-size:11px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:.05em;">
                        Message Thread
                    </div>
                    <div style="max-height:200px;overflow-y:auto;padding:10px 14px;display:flex;flex-direction:column;gap:8px;">
                        {foreach from=$s9_record.messages_cache item=msg}
                            <div style="display:flex;gap:8px;align-items:flex-start;">
                                <div style="width:28px;height:28px;border-radius:50%;background:{if $msg.is_from_s9 ?? false}#dbeafe{else}#d1fae5{/if};display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:11px;font-weight:700;color:#1e3a5f;">
                                    {if $msg.is_from_s9 ?? false}R{else}E{/if}
                                </div>
                                <div>
                                    <div style="font-size:11px;color:#6b7280;">{$msg.sender_name|default:$msg.reviewer_name|default:'Reviewer'|escape}{if $msg.sender_role|default:''} ({$msg.sender_role|escape}){/if} · {$msg.created_at|default:''|escape}</div>
                                    <div style="font-size:13px;color:#111827;">{$msg.comment_text|escape|nl2br}</div>
                                </div>
                            </div>
                        {/foreach}
                    </div>
                </div>
            {/if}

            {* ── Send message ── *}
            <div style="display:flex;gap:8px;margin-bottom:14px;">
                <textarea id="s9MessageText" rows="2" placeholder="Send a message to the reviewer..."
                    style="flex:1;border:1px solid #d1d5db;border-radius:6px;padding:7px 10px;font-size:13px;resize:vertical;"></textarea>
                <button onclick="s9SendMessage()"
                    style="background:#1e3a5f;color:#fff;border:none;border-radius:6px;padding:8px 14px;font-size:13px;cursor:pointer;align-self:flex-end;">
                    Send
                </button>
            </div>

            {* ── Toolbar ── *}
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button onclick="s9Sync()"
                    style="background:#f3f4f6;color:#374151;border:1px solid #d1d5db;border-radius:6px;padding:6px 14px;font-size:12px;cursor:pointer;font-weight:500;">
                    ↻ Sync Status
                </button>
                <button onclick="s9ToggleOpen()"
                    style="background:#f3f4f6;color:#374151;border:1px solid #d1d5db;border-radius:6px;padding:6px 14px;font-size:12px;cursor:pointer;font-weight:500;">
                    {if $s9_record.open_review}🔓 Open Review ON{else}🔒 Open Review OFF{/if}
                </button>
                {if $s9_record.s9_review_url}
                    <a href="{$s9_record.s9_review_url|escape}" target="_blank" rel="noopener noreferrer"
                        style="background:#f3f4f6;color:#374151;border:1px solid #d1d5db;border-radius:6px;padding:6px 14px;font-size:12px;cursor:pointer;font-weight:500;text-decoration:none;">
                        View on Scholar9 ↗
                    </a>
                {/if}
            </div>
            {if $s9_record.last_synced_at}
                <p style="font-size:11px;color:#9ca3af;margin-top:8px;">Last synced: {$s9_record.last_synced_at|escape}</p>
            {/if}

        {/if}

        <div id="s9Spinner" style="display:none;text-align:center;padding:8px;color:#6b7280;font-size:13px;">
            Processing…
        </div>

    </div>
</div>

<script>
(function() {
    var ajaxUrl   = {$s9_ajax_url|json_encode};
    var subId     = {$submission->getId()};
    var roundId   = 0; // populated by OJS page JS if available
    var csrfToken = '{$s9_csrf_token|escape:"javascript"}';

    function post(op, extra) {
        $('#s9Spinner').show();
        return $.ajax({
            url:  ajaxUrl,
            type: 'POST',
            data: $.extend({ op: op, submissionId: subId, reviewRoundId: roundId, csrfToken: csrfToken }, extra),
            dataType: 'json'
        }).always(function() {
            $('#s9Spinner').hide();
        });
    }

    window.s9Create = function() {
        var url = $('#s9ManuscriptUrl').val();
        post('create', { manuscriptUrl: url }).done(function(r) {
            if (r.success) { location.reload(); }
            else           { alert(r.error || 'Failed to send to Scholar9'); }
        }).fail(function() { alert('Network error'); });
    };

    window.s9Sync = function() {
        post('sync').done(function(r) {
            if (r.success) { location.reload(); }
            else           { alert(r.error || 'Sync failed'); }
        });
    };

    window.s9SendMessage = function() {
        var text = $('#s9MessageText').val().trim();
        if (!text) return;
        post('send_message', { text: text }).done(function(r) {
            if (r.success) { $('#s9MessageText').val(''); location.reload(); }
            else           { alert(r.error || 'Failed to send message'); }
        });
    };

    window.s9ToggleOpen = function() {
        post('toggle_open').done(function(r) {
            if (r.success) { location.reload(); }
        });
    };
})();
</script>
