<div style="margin-top:1.5rem;border:1px solid #d1d5db;border-radius:8px;overflow:hidden;">

    <div style="background:#1e3a5f;color:#fff;padding:10px 16px;font-size:13px;font-weight:600;">
        External Peer Review Status
    </div>

    <div style="padding:14px 16px;background:#fff;">

        {assign var="status" value=$s9_record.status}

        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
            {if $status == 'decision_returned'}
                <span style="width:10px;height:10px;border-radius:50%;background:#10b981;flex-shrink:0;"></span>
                <span style="font-size:13px;font-weight:600;color:#065f46;">Review Complete</span>
            {elseif $status == 'under_review' || $status == 'awaiting_reviewer_response'}
                <span style="width:10px;height:10px;border-radius:50%;background:#3b82f6;flex-shrink:0;"></span>
                <span style="font-size:13px;font-weight:600;color:#1e40af;">Under Review</span>
            {elseif $status == 'ai_analysis'}
                <span style="width:10px;height:10px;border-radius:50%;background:#8b5cf6;flex-shrink:0;"></span>
                <span style="font-size:13px;font-weight:600;color:#5b21b6;">AI Analysis in Progress</span>
            {elseif $status == 'recommendation_ready'}
                <span style="width:10px;height:10px;border-radius:50%;background:#f59e0b;flex-shrink:0;"></span>
                <span style="font-size:13px;font-weight:600;color:#92400e;">Recommendation Ready — Editor Deciding</span>
            {elseif $status == 'reviewer_declined' || $status == 'cancelled'}
                <span style="width:10px;height:10px;border-radius:50%;background:#ef4444;flex-shrink:0;"></span>
                <span style="font-size:13px;font-weight:600;color:#991b1b;">
                    {if $status == 'reviewer_declined'}Reviewer Declined{else}Review Cancelled{/if}
                </span>
            {else}
                <span style="width:10px;height:10px;border-radius:50%;background:#f59e0b;flex-shrink:0;"></span>
                <span style="font-size:13px;font-weight:600;color:#92400e;">Awaiting Assignment</span>
            {/if}
        </div>

        {if $s9_record.ai_summary && $status != 'pending_assignment'}
            <div style="background:#f0f9ff;border-left:3px solid #0ea5e9;padding:10px 14px;border-radius:0 6px 6px 0;margin-bottom:10px;">
                <div style="font-size:11px;font-weight:600;color:#0369a1;margin-bottom:3px;text-transform:uppercase;">AI Review Summary</div>
                <p style="font-size:13px;color:#0c4a6e;margin:0;">{$s9_record.ai_summary|escape}</p>
            </div>
        {/if}

        {if $s9_record.open_review && $s9_record.s9_review_url}
            <a href="{$s9_record.s9_review_url|escape}" target="_blank" rel="noopener noreferrer"
                style="display:inline-flex;align-items:center;gap:6px;color:#1e3a5f;font-size:13px;font-weight:500;text-decoration:none;border:1px solid #93c5fd;padding:6px 12px;border-radius:6px;">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                </svg>
                View Transparent Peer Review
            </a>
        {/if}

    </div>
</div>
