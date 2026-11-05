'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const scriptPath = path.resolve(__dirname, '..', 'js', 'workflowIntegration.js');
const source = fs.readFileSync(scriptPath, 'utf8');
const css = fs.readFileSync(path.resolve(__dirname, '..', 'css', 'workflow.css'), 'utf8');
assert.match(
    source,
    /payload\.warning \|\| 'The submission was sent to Scholar9\.'/,
    'surfaces partial multi-reviewer assignment warnings after review creation'
);
assert.match(source, /Request credentials:<\/strong>/, 'explains how an unconfigured journal requests Scholar9 credentials');
assert.match(source, /Client ID, one-time Client Secret, and Journal Slug/, 'lists every credential required to complete setup');
assert.match(source, /:href="settingsUrl"/, 'provides a direct link to the native OJS plugin settings page');
assert.match(source, /role="switch"/, 'uses accessible switch controls for review visibility');
assert.match(source, /toggle_full_review/, 'supports paper-wide full review visibility');
assert.match(source, /\/open-review\//, 'builds the reviewer-specific Scholar9 public URL');
assert.match(source, /\/open-paper-link\//, 'builds the paper-wide Scholar9 public URL');
assert.match(source, /View reviewer page/, 'shows the reviewer public link only when visibility is enabled');
assert.match(source, /View full review/, 'shows the full-review public link only when visibility is enabled');
assert.match(source, /currentReviewerOpenReview/, 'keeps open-review visibility scoped to the selected reviewer');
assert.match(css, /\.s9-toggle\.is-on/, 'styles a clear on state for visibility switches');
const components = Object.create(null);
const workflowExtensions = [];

const registry = {
    registerComponent(name, component) {
        components[name] = component;
    },
    storeExtend(storeName, callback) {
        assert.equal(storeName, 'workflow');
        workflowExtensions.push(callback);
    }
};

const browserWindow = {
    location: {href: 'http://localhost/demo-ojs/index.php/demo/dashboard/editorial'},
    scholar9ReviewConfig: {
        configured: true,
        settingsUrl: 'http://localhost/demo-ojs/index.php/demo/management/settings/website#plugins',
        actionUrl: 'http://localhost/demo-ojs/index.php/demo/scholar9Review/action',
        statusUrl: 'http://localhost/demo-ojs/index.php/demo/scholar9Review/status',
        csrfToken: 'test-csrf',
        maxReviewers: 5
    },
    pkp: {
        registry,
        const: {
            WORKFLOW_STAGE_ID_SUBMISSION: 1,
            WORKFLOW_STAGE_ID_EXTERNAL_REVIEW: 3
        }
    },
    setInterval,
    clearInterval
};

const context = vm.createContext({
    window: browserWindow,
    URL,
    URLSearchParams,
    AbortController,
    DOMException,
    fetch: async () => {
        throw new Error('Unexpected network call in workflow registration test');
    },
    console,
    setTimeout,
    clearTimeout
});

vm.runInContext(source, context, {filename: scriptPath});

assert.equal(workflowExtensions.length, 1, 'registers one workflow extension');
assert.ok(components.Scholar9WorkflowWorkspace, 'registers the workspace component');
assert.ok(components.Scholar9WorkflowShortcut, 'registers the submission shortcut');

function createWorkflowStore(dashboardPage = 'editorialDashboard') {
    const extensions = Object.create(null);
    const navigations = [];
    const store = {
        dashboardPage,
        extender: {
            extendFn(name, callback) {
                extensions[name] = callback;
            }
        },
        navigateToMenu(key) {
            navigations.push(key);
        }
    };
    workflowExtensions[0]({store});
    return {store, extensions, navigations};
}

function workflowGroups() {
    return [{
        key: 'workflow',
        items: [
            {key: 'workflow_1', label: 'Submission'},
            {key: 'workflow_3', label: 'Review'},
            {key: 'workflow_4', label: 'Copyediting'}
        ]
    }];
}

function argsFor(submissionId, selectedMenuState, dashboardPage) {
    const args = {
        submission: {id: submissionId},
        selectedMenuState
    };
    if (dashboardPage) {
        args.dashboardPage = dashboardPage;
    }
    return args;
}

function scholar9State() {
    return {
        primaryMenuItem: 'scholar9Review',
        title: 'Workflow: Review with Scholar9'
    };
}

const first = createWorkflowStore();
const originalGroups = workflowGroups();
const firstMenu = first.extensions.getMenuItems(
    originalGroups,
    argsFor(1, {primaryMenuItem: 'workflow', stageId: 1}, 'editorialDashboard')
);

assert.deepEqual(
    firstMenu[0].items.map((item) => item.key),
    ['workflow_1', 'scholar9_review', 'workflow_3', 'workflow_4'],
    'inserts Scholar9 immediately before the native Review stage'
);
assert.equal(
    Object.hasOwn(firstMenu[0].items[1].state, 'stageId'),
    false,
    'uses a plugin-owned state instead of reusing a native stage'
);
assert.equal(
    originalGroups[0].items.some((item) => item.key === 'scholar9_review'),
    false,
    'does not mutate OJS menu input'
);

const repeatedMenu = first.extensions.getMenuItems(
    firstMenu,
    argsFor(1, {primaryMenuItem: 'workflow', stageId: 1}, 'editorialDashboard')
);
assert.equal(
    repeatedMenu[0].items.filter((item) => item.key === 'scholar9_review').length,
    1,
    'does not duplicate the menu item when OJS recalculates navigation'
);

const submissionOnePrimary = first.extensions.getPrimaryItems(
    [{component: 'NativeSubmission'}],
    argsFor(1, scholar9State(), 'editorialDashboard')
);
assert.equal(submissionOnePrimary.length, 1);
assert.equal(submissionOnePrimary[0].component, 'Scholar9WorkflowWorkspace');
assert.equal(submissionOnePrimary[0].props.submissionId, 1);
assert.equal(submissionOnePrimary[0].props.namespace, 'scholar9-1');

for (const extensionName of [
    'getHeaderItems',
    'getSecondaryItems',
    'getActionItems',
    'getPrimaryControlsLeft',
    'getPrimaryControlsRight'
]) {
    const hiddenItems = first.extensions[extensionName](
        [{component: 'NativeItem'}],
        argsFor(1, scholar9State())
    );
    assert.equal(
        hiddenItems.length,
        0,
        `${extensionName} removes native-stage content inside the Scholar9 state`
    );
}

const nativeItems = [{component: 'NativeSubmission'}];
assert.equal(
    first.extensions.getPrimaryItems(
        nativeItems,
        argsFor(1, {primaryMenuItem: 'workflow', stageId: 1})
    ),
    nativeItems,
    'leaves the native Submission primary content untouched'
);

const shortcutItems = first.extensions.getActionItems(
    [{component: 'NativeAction'}],
    argsFor(1, {primaryMenuItem: 'workflow', stageId: 1})
);
assert.equal(shortcutItems.at(-1).component, 'Scholar9WorkflowShortcut');
assert.equal(shortcutItems.at(-1).props.submissionId, 1);
shortcutItems.at(-1).props.onOpen();
assert.deepEqual(first.navigations, ['scholar9_review']);

// OJS disposes and recreates the workflow store when the editor switches submissions.
// The registered store extension must decorate every new store with the current ID.
const second = createWorkflowStore();
const secondMenu = second.extensions.getMenuItems(
    workflowGroups(),
    argsFor(2, {primaryMenuItem: 'workflow', stageId: 1})
);
assert.equal(
    secondMenu[0].items.some((item) => item.key === 'scholar9_review'),
    true,
    'keeps the Scholar9 tab when a second submission is opened'
);
const submissionTwoPrimary = second.extensions.getPrimaryItems(
    [{component: 'NativeSubmission'}],
    argsFor(2, scholar9State())
);
assert.equal(submissionTwoPrimary[0].props.submissionId, 2);
assert.equal(submissionTwoPrimary[0].props.namespace, 'scholar9-2');
assert.notEqual(
    submissionTwoPrimary[0].props.submissionId,
    submissionOnePrimary[0].props.submissionId,
    'does not reuse data keys from the previous submission'
);

const returned = createWorkflowStore();
const returnedPrimary = returned.extensions.getPrimaryItems(
    [],
    argsFor(1, scholar9State())
);
assert.equal(returnedPrimary[0].props.submissionId, 1);
assert.equal(returnedPrimary[0].props.namespace, 'scholar9-1');

const publicDashboard = createWorkflowStore('authorDashboard');
const authorGroups = workflowGroups();
assert.strictEqual(
    publicDashboard.extensions.getMenuItems(authorGroups, argsFor(1, null)),
    authorGroups,
    'does not throw outside the editorial dashboard'
);
assert.equal(
    publicDashboard.extensions.getMenuItems(workflowGroups(), argsFor(1, null))[0].items
        .some((item) => item.key === 'scholar9_review'),
    false,
    'does not expose the editor integration on other dashboards'
);

assert.throws(
    () => components.Scholar9WorkflowWorkspace.methods.assertRecordScope.call(
        {submissionId: 2},
        {submission_id: 1}
    ),
    /different submission/,
    'rejects a stale or cross-submission backend record'
);
assert.doesNotThrow(
    () => components.Scholar9WorkflowWorkspace.methods.assertRecordScope.call(
        {submissionId: 2},
        {submission_id: 2}
    )
);

const workspace = components.Scholar9WorkflowWorkspace;
const attachmentMethods = {
    messageAttachment: workspace.methods.messageAttachment
};
const senderMethods = {
    isPublisherMessage: workspace.methods.isPublisherMessage,
    currentReviewer: {name: 'Dr. Reviewer'}
};
assert.equal(
    workspace.methods.messageSender.call(senderMethods, {
        from: 'reviewer',
        sender_name: 'Dr. Reviewer',
        sender_role: 'Reviewer'
    }),
    'Dr. Reviewer (Reviewer)',
    'shows the Scholar9 reviewer name with its designation'
);
assert.equal(
    workspace.methods.messageSender.call(senderMethods, {
        from: 'editor',
        sender_name: 'OJSCloud',
        sender_role: 'Publisher'
    }),
    'OJSCloud (Publisher)',
    'shows the OJS sender name with its publisher designation'
);
assert.equal(
    workspace.methods.messageSender.call(senderMethods, {
        from: 'editor',
        sender_name: 'Journal Operations',
        sender_role: 'Journal Manager'
    }),
    'Journal Operations (Journal Manager)',
    'supports journal-manager designations without trusting arbitrary role text'
);
const nativeAttachment = workspace.methods.messageAttachment.call(attachmentMethods, {
    text: 'File shared: paper.pdf\nhttps://ojs.example.test/download?token=safe'
});
assert.equal(nativeAttachment.name, 'paper.pdf', 'recognizes the Scholar9-native File shared filename');
assert.equal(nativeAttachment.url, 'https://ojs.example.test/download?token=safe', 'recognizes the Scholar9-native file link');
const legacyAttachment = workspace.methods.messageAttachment.call(attachmentMethods, {
    text: 'Shared file: legacy.pdf\nhttps://ojs.example.test/legacy'
});
assert.equal(legacyAttachment.name, 'legacy.pdf', 'keeps the legacy Shared file filename');
assert.equal(legacyAttachment.url, 'https://ojs.example.test/legacy', 'keeps the legacy Shared file link');
assert.equal(
    workspace.methods.messageAttachment.call(attachmentMethods, {
        text: 'A normal message containing https://example.test/reference'
    }),
    null,
    'does not turn an ordinary message URL into a file attachment'
);
const unsafeAttachment = workspace.methods.messageAttachment.call(attachmentMethods, {
    attachment_name: 'unsafe.pdf',
    attachment_url: 'javascript:alert(1)'
});
assert.equal(unsafeAttachment.name, 'unsafe.pdf');
assert.equal(unsafeAttachment.url, '', 'rejects executable attachment URLs');
assert.equal(
    workspace.methods.messageTextValue.call(attachmentMethods, {
        text: 'File shared: paper.pdf',
        attachment_name: 'paper.pdf'
    }),
    '',
    'suppresses duplicate native attachment text when the file card is shown'
);

const scopedMessages = workspace.computed.messages.call({
    currentReviewer: {id: 'reviewer-a'},
    record: {
        messages_cache: [
            {id: 'a', reviewer_id: 'reviewer-a', from: 'editor'},
            {id: 'b', reviewer_id: 'reviewer-b', from: 'editor'},
            {id: 'legacy', from: 'editor'},
            {id: 'alias', reviewer_details_id: 'reviewer-a', from: 'reviewer'}
        ]
    }
});
assert.equal(
    scopedMessages.map((message) => message.id).join(','),
    'a,legacy,alias',
    'does not leak publisher files or messages into another reviewer conversation'
);

const revisionMethods = workspace.methods;
const revisionToneContext = {isRevisionMessage: revisionMethods.isRevisionMessage};
assert.equal(
    revisionMethods.isRevisionMessage({comment_status: '2', text: 'Revision Status: Clarification'}),
    true,
    'recognizes Scholar9 comment_status 2 as a genuine revision request'
);
assert.equal(
    revisionMethods.isRevisionMessage({comment_status: null, text: 'Test 1Revision Status: Minor Revision'}),
    false,
    'does not style ordinary typed text as revision metadata'
);
assert.equal(
    revisionMethods.isRevisionMessage({comment_status: 'major_revision', text: 'Revision Status: Major Revision'}),
    false,
    'requires the exact Scholar9 comment_status value 2'
);
assert.equal(
    revisionMethods.isRevisionMessage({status: '2', text: 'Revision Status: Clarification'}),
    true,
    'falls back to the legacy status field for cached messages synced before comment_status was tracked'
);
assert.equal(
    revisionMethods.isRevisionMessage({status: '1', text: 'Review Request Accepted'}),
    false,
    'the legacy status fallback still requires the exact value 2'
);
assert.equal(
    revisionMethods.revisionTone.call(revisionToneContext, {
        comment_status: '2',
        text: 'test 3\nRevision Status: Clarification'
    }),
    'clarification',
    'extracts the revision subtype only from an authenticated revision event'
);

const reviewerRevisionContext = {
    record: {
        messages_cache: [
            {reviewer_details_id: 'reviewer-a', comment_status: '2', text: 'Test 1\nRevision Status: Minor Revision', created_at: '2026-08-20T11:21:44+05:30'},
            {reviewer_details_id: 'reviewer-a', comment_status: '2', text: 'test 2\nRevision Status: Major Revision', created_at: '2026-08-20T11:21:59+05:30'},
            {reviewer_details_id: 'reviewer-a', comment_status: '2', text: 'test 3\nRevision Status: Clarification', created_at: '2026-08-20T11:22:05+05:30'},
            {reviewer_details_id: 'reviewer-a', comment_status: null, text: 'Test 1Revision Status: Minor Revision', created_at: '2026-08-20T11:28:43+05:30'}
        ]
    },
    messages: [],
    isRevisionMessage: revisionMethods.isRevisionMessage,
    revisionTone: revisionMethods.revisionTone,
    statusLabel: revisionMethods.statusLabel
};
assert.equal(
    revisionMethods.reviewerDisplayStatus.call(
        reviewerRevisionContext,
        {id: 'reviewer-a', status: 'under_review'}
    ),
    'Under Review / Clarification',
    'shows the latest genuine revision subtype without being overwritten by normal typed messages'
);

assert.equal(source.includes('v-html'), false, 'renders messages as escaped Vue text');
assert.equal(
    source.includes('reviewer.designation || reviewer.expertise || reviewer.email'),
    false,
    'never displays a reviewer email as profile or designation text'
);
assert.equal(
    source.includes('placeholder="Name, expertise, or email"'),
    false,
    'does not expose reviewer email as an OJS search/display field'
);
assert.equal(
    source.includes('email: stringValue'),
    false,
    'does not retain reviewer email in browser-facing normalized reviewer objects'
);
assert.equal(source.includes('querySelector'), false, 'does not query or rewrite OJS DOM');
assert.equal(source.includes('MutationObserver'), false, 'does not use DOM observers');
assert.equal(source.includes('appendChild'), false, 'does not append overlay UI to the page');
assert.equal(source.includes("cache: 'no-store'"), true, 'does not reuse stale Scholar9 status responses');
assert.equal(
    source.includes('reviewerPhotoUrl(reviewer)') && source.includes('reviewerPhotoUrl(currentReviewer)'),
    true,
    'renders synchronized Scholar9 profile photos in the reviewer list and conversation header'
);
assert.equal(
    source.includes("'1': 'Review Request Accepted'") && source.includes('messageTextValue(message)'),
    true,
    'renders legacy cached acceptance events with a default message instead of an empty chat card'
);
assert.equal(
    source.includes('@error="hideReviewerPhoto(reviewer)"'),
    true,
    'falls back to reviewer initials when a profile image cannot load'
);
assert.equal(
    source.includes('failedPhotoUrls.push(url)'),
    true,
    'remembers failed photo URLs and prevents broken-image render loops'
);
assert.equal(
    workspace.computed.isConversationClosed.call({status: 'reviewer_completed'}),
    true,
    'closes the composer after Scholar9 submits the final review'
);
assert.equal(
    workspace.computed.isConversationClosed.call({status: 'under_review'}),
    false,
    'keeps the composer available while the review is active'
);
assert.equal(
    workspace.computed.isCurrentConversationClosed.call({
        isConversationClosed: false,
        currentReviewer: {status: 'reviewer_completed'}
    }),
    true,
    'locks a completed reviewer thread while other reviewer threads remain active'
);
assert.equal(
    workspace.computed.isCurrentConversationClosed.call({
        isConversationClosed: false,
        currentReviewer: {status: 'under_review'}
    }),
    false,
    'keeps the selected active reviewer thread writable'
);
assert.equal(
    source.includes('<form v-if="!isCurrentConversationClosed" class="s9-composer"'),
    true,
    'renders the message composer only for active conversations'
);
assert.equal(
    source.includes('class="s9-rating-summary"') && source.includes('messageRatingCriteria(message, ratingIndex)'),
    true,
    'renders structured reviewer ratings as labeled star rows'
);
assert.equal(
    source.includes('rate_reviewer') && source.includes('Submit reviewer rating'),
    true,
    'offers the publisher a one-time reviewer rating action after completion'
);
assert.equal(
    source.includes('<input type="text" v-model="publisherRatingComment"')
        && !source.includes('<textarea v-model="publisherRatingComment"'),
    true,
    'uses a compact single-line input for optional publisher feedback'
);
assert.equal(
    css.includes('.s9-publisher-rating {')
        && css.includes('.s9-chat-pane {')
        && css.includes('overflow-y: auto')
        && css.includes('.s9-publisher-rating { background: #fff; border-top: 1px solid #dbe5f1; flex: 0 0 auto; overflow: visible;')
        && css.includes('.s9-publisher-rating footer'),
    true,
    'uses one conversation scrollbar and does not add a second scrollbar to the rating panel'
);
assert.deepEqual(
    Array.from(workspace.methods.messageRatingValues.call({}, {
        rating_data: [3, 4, 5, 2, 1, 99]
    })),
    [3, 4, 5, 2, 1],
    'normalizes structured Scholar9 rating data and rejects invalid star values'
);
assert.deepEqual(
    Array.from(workspace.methods.messageRatingValues.call({}, {
        text: 'Clear review\nRating: 3, 3, 4, 5, 2'
    })),
    [3, 3, 4, 5, 2],
    'supports legacy rating lines while older Scholar9 records are synchronized'
);
assert.equal(
    workspace.methods.messageTextValue.call(attachmentMethods, {
        text: 'Clear review\nRating: 3, 3, 4, 5, 2'
    }),
    'Clear review',
    'does not duplicate raw comma-separated values beside the star display'
);
assert.equal(
    source.includes('The reviewer conversation is closed.'),
    true,
    'renders a professional completed-review summary'
);
assert.equal(
    source.includes('startAutoSync') && source.includes('pollStatus') && source.includes('visibilitychange'),
    true,
    'refreshes an open OJS conversation after webhook updates without a page reload'
);
assert.equal(
    source.includes('this.autoSyncBusy || !this.isStarted'),
    true,
    'prevents overlapping or unnecessary automatic status requests'
);
assert.equal(
    source.includes('document.hidden'),
    true,
    'pauses background polling while the workflow tab is not visible'
);

// ── Rate-limit backoff ──────────────────────────────────────────────────────
{
    const rateLimitContext = {
        rateLimitedUntil: 0,
        rateLimitCountdown: 0,
        rateLimitTimer: null,
        applyRateLimit: revisionMethods.applyRateLimit
    };
    revisionMethods.registerRateLimit.call(rateLimitContext, {
        message: 'The Scholar9 request failed.',
        retryAfter: 42
    });
    assert.equal(
        rateLimitContext.rateLimitCountdown,
        42,
        'starts a countdown from the server-supplied Retry-After seconds'
    );
    assert.ok(
        rateLimitContext.rateLimitedUntil > Date.now(),
        'locks actions out until the Retry-After deadline'
    );
    clearInterval(rateLimitContext.rateLimitTimer);

    const fallbackContext = {
        rateLimitedUntil: 0,
        rateLimitCountdown: 0,
        rateLimitTimer: null,
        applyRateLimit: revisionMethods.applyRateLimit
    };
    revisionMethods.registerRateLimit.call(fallbackContext, {
        message: 'Scholar9 rate limit reached. Please wait before trying again.'
    });
    assert.equal(
        fallbackContext.rateLimitCountdown,
        30,
        'falls back to a 30s cooldown when Scholar9 does not supply Retry-After'
    );
    clearInterval(fallbackContext.rateLimitTimer);

    const unrelatedErrorContext = {
        rateLimitedUntil: 0,
        rateLimitCountdown: 0,
        rateLimitTimer: null,
        applyRateLimit: revisionMethods.applyRateLimit
    };
    revisionMethods.registerRateLimit.call(unrelatedErrorContext, {
        message: 'Select a reviewer before sending a message.'
    });
    assert.equal(
        unrelatedErrorContext.rateLimitCountdown,
        0,
        'does not lock out actions for ordinary validation errors'
    );

    const shorterRetry = {
        rateLimitedUntil: Date.now() + 60000,
        rateLimitCountdown: 60,
        rateLimitTimer: null
    };
    revisionMethods.applyRateLimit.call(shorterRetry, 5);
    assert.equal(
        shorterRetry.rateLimitCountdown,
        60,
        'never shortens an existing, longer rate-limit lockout'
    );

    const rateLimitedComputed = workspace.computed.rateLimited;
    assert.equal(rateLimitedComputed.call({rateLimitCountdown: 5}), true);
    assert.equal(rateLimitedComputed.call({rateLimitCountdown: 0}), false);
    assert.equal(
        workspace.computed.canCreate.call({
            manuscriptFileId: '1',
            selectedParameterIds: ['a'],
            selectedReviewerIds: ['b'],
            operation: '',
            rateLimited: true
        }),
        false,
        'blocks starting a new Scholar9 review while rate-limited'
    );
}
assert.equal(
    source.includes('error.retryAfter = retryAfter'),
    true,
    'propagates the server Retry-After seconds onto thrown request errors'
);
assert.equal(
    source.includes('this.rateLimitCountdown > 0'),
    true,
    'pauses automatic status polling while a rate-limit cooldown is active'
);

console.log('Scholar9 workflow integration tests passed.');
