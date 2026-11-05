(function () {
    'use strict';

    var config = window.scholar9ReviewConfig || {};
    var registry = window.pkp && window.pkp.registry;
    var MENU_KEY = 'scholar9_review';
    var STATE_KEY = 'scholar9Review';
    var WORKSPACE_COMPONENT = 'Scholar9WorkflowWorkspace';
    var SHORTCUT_COMPONENT = 'Scholar9WorkflowShortcut';

    if (!registry || window.__scholar9WorkflowIntegrationRegistered) {
        return;
    }
    window.__scholar9WorkflowIntegrationRegistered = true;

    function arrayValue(value) {
        return Array.isArray(value) ? value : [];
    }

    function stringValue(value) {
        return value === null || typeof value === 'undefined' ? '' : String(value);
    }

    function reviewerId(reviewer) {
        return stringValue(reviewer && (
            reviewer.id
            || reviewer._id
            || reviewer.reviewer_id
            || reviewer.reviewer_details_id
            || reviewer.reviewerDetailsId
        ));
    }

    function messageReviewerId(message) {
        var nested = message && message.reviewer;
        return stringValue(message && (
            message.reviewer_id
            || message.reviewerId
            || message.reviewer_details_id
            || message.reviewerDetailsId
            || (nested && (
                nested.id
                || nested._id
                || nested.reviewer_id
                || nested.reviewer_details_id
                || nested.reviewerDetailsId
            ))
        ));
    }

    function messageTimestamp(message) {
        var value = stringValue(message && (
            message.created_at
            || message.createdAt
            || message.timestamp
            || message.comment_date
            || message.date
        ));
        var parsed = Date.parse(value);
        return isNaN(parsed) ? 0 : parsed;
    }

    function reviewerName(reviewer) {
        return stringValue(
            reviewer && (reviewer.name || reviewer.full_name || reviewer.display_name)
        ) || 'Reviewer';
    }

    function normalizedReviewStatus(value) {
        var status = stringValue(value || 'pending').trim().toLowerCase().replace(/[ -]+/g, '_');
        var aliases = {
            '0': 'pending_assignment',
            '1': 'under_review',
            '2': 'cancelled',
            '3': 'reviewer_completed',
            '4': 'reviewer_declined',
            pending: 'pending_assignment',
            requested: 'pending_assignment',
            review_requested: 'pending_assignment',
            accepted: 'under_review',
            review_accepted: 'under_review',
            review_request_accepted: 'under_review',
            completed: 'reviewer_completed',
            review_completed: 'reviewer_completed',
            declined: 'reviewer_declined',
            rejected: 'reviewer_declined',
            request_declined: 'reviewer_declined',
            request_rejected: 'reviewer_declined',
            canceled: 'cancelled',
            request_cancelled: 'cancelled',
            request_canceled: 'cancelled'
        };
        return aliases[status] || status;
    }

    function reviewStatusLabel(value) {
        var labels = {
            pending_assignment: 'Review Requested',
            awaiting_reviewer_response: 'Review Requested',
            under_review: 'Under Review',
            reviewer_completed: 'Review Completed',
            decision_returned: 'Review Completed',
            reviewer_declined: 'Review Rejected',
            cancelled: 'Review Request Cancelled'
        };
        var status = normalizedReviewStatus(value);
        return labels[status] || status.replace(/_/g, ' ');
    }

    function reviewStatusTone(value) {
        var status = normalizedReviewStatus(value);
        if (status === 'under_review') { return 'orange'; }
        if (status === 'reviewer_completed' || status === 'decision_returned') { return 'green'; }
        if (status === 'reviewer_declined') { return 'red'; }
        if (status === 'cancelled') { return 'purple'; }
        return 'blue';
    }

    function normalizeReviewers(record) {
        var reviewers = arrayValue(record && record.reviewers_cache)
            .filter(function (reviewer) { return reviewerId(reviewer); })
            .map(function (reviewer) {
                return {
                    id: reviewerId(reviewer),
                    name: reviewerName(reviewer),
                    status: stringValue(reviewer.status || reviewer.assignment_status || 'pending'),
                    designation: stringValue(reviewer.designation || reviewer.role),
                    expertise: reviewer.expertise || '',
                    slug: stringValue(reviewer.slug || reviewer.profile_slug || reviewer.username),
                    open_review: !!reviewer.open_review,
                    photoUrl: safeHttpUrl(reviewer.photo_url || reviewer.avatar_url || reviewer.photoUrl)
                };
            });

        if (!reviewers.length && record && record.reviewer_id) {
            reviewers.push({
                id: stringValue(record.reviewer_id),
                name: stringValue(record.reviewer_name) || 'Reviewer',
                status: stringValue(record.status) || 'pending',
                designation: '',
                expertise: '',
                slug: '',
                open_review: !!record.open_review,
                photoUrl: safeHttpUrl(record.reviewer_photo_url || record.photo_url)
            });
        }
        return reviewers;
    }

    function normalizeReviewerResult(reviewer) {
        var expertise = reviewer && reviewer.expertise;
        if (Array.isArray(expertise)) {
            expertise = expertise.join(', ');
        }
        return {
            id: reviewerId(reviewer),
            name: reviewerName(reviewer),
            status: stringValue(reviewer && reviewer.status),
            designation: stringValue(
                reviewer && (reviewer.designation || reviewer.role || reviewer.position)
            ),
            expertise: stringValue(expertise),
            slug: stringValue(reviewer && (reviewer.slug || reviewer.profile_slug || reviewer.username)),
            photoUrl: safeHttpUrl(reviewer && (reviewer.photo_url || reviewer.avatar_url))
        };
    }

    function safeHttpUrl(value) {
        var text = stringValue(value).trim();
        if (!text) {
            return '';
        }
        try {
            var parsed = new URL(text, window.location.href);
            if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
                return '';
            }
            return parsed.href;
        } catch (error) {
            return '';
        }
    }

    function appendQuery(url, values) {
        var parsed = new URL(url, window.location.href);
        Object.keys(values).forEach(function (key) {
            if (values[key] !== null && typeof values[key] !== 'undefined') {
                parsed.searchParams.set(key, String(values[key]));
            }
        });
        return parsed.href;
    }

    function parseJsonResponse(response) {
        var contentType = response.headers.get('content-type') || '';
        if (response.redirected || contentType.indexOf('application/json') === -1) {
            throw new Error(
                response.status === 401 || response.status === 403
                    ? 'Your OJS session expired. Sign in again and reopen this submission.'
                    : 'OJS returned an invalid response. Check the PHP error log.'
            );
        }
        return response.json().then(function (payload) {
            if (!response.ok || (payload && payload.error)) {
                var error = new Error((payload && payload.error) || 'The Scholar9 request failed.');
                var retryAfter = Number(payload && payload.retry_after);
                if (retryAfter > 0) {
                    error.retryAfter = retryAfter;
                }
                throw error;
            }
            return payload || {};
        });
    }

    function statusRequest(submissionId, reviewRoundId, signal) {
        return fetch(appendQuery(config.statusUrl, {
            submissionId: submissionId,
            reviewRoundId: reviewRoundId || 0
        }), {
            method: 'GET',
            cache: 'no-store',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            signal: signal
        }).then(parseJsonResponse);
    }

    function actionRequest(operation, submissionId, reviewRoundId, values, signal) {
        var body = new URLSearchParams();
        body.set('op', operation);
        body.set('submissionId', String(submissionId));
        body.set('reviewRoundId', String(reviewRoundId || 0));
        body.set('csrfToken', stringValue(config.csrfToken));
        Object.keys(values || {}).forEach(function (key) {
            if (values[key] !== null && typeof values[key] !== 'undefined') {
                body.set(key, String(values[key]));
            }
        });

        return fetch(config.actionUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body.toString(),
            signal: signal
        }).then(parseJsonResponse);
    }

    var Scholar9WorkflowShortcut = {
        name: SHORTCUT_COMPONENT,
        props: {
            submissionId: {type: Number, required: true},
            reviewRoundId: {type: Number, default: 0},
            onOpen: {type: Function, required: true}
        },
        data: function () {
            return {
                started: false,
                status: '',
                controller: null
            };
        },
        watch: {
            submissionId: {
                immediate: true,
                handler: function () {
                    this.loadStatus();
                }
            }
        },
        beforeUnmount: function () {
            if (this.controller) {
                this.controller.abort();
            }
        },
        methods: {
            loadStatus: function () {
                var self = this;
                var expectedSubmissionId = Number(this.submissionId);
                if (this.controller) {
                    this.controller.abort();
                }
                this.controller = new AbortController();
                this.started = false;
                this.status = '';
                statusRequest(expectedSubmissionId, this.reviewRoundId, this.controller.signal)
                    .then(function (payload) {
                        if (Number(self.submissionId) !== expectedSubmissionId) {
                            return;
                        }
                        self.started = !!(payload.record && payload.record.s9_paper_id);
                        self.status = stringValue(payload.status).replace(/_/g, ' ');
                    })
                    .catch(function (error) {
                        if (error.name !== 'AbortError') {
                            self.status = '';
                        }
                    });
            }
        },
        template:
            '<section class="s9-shortcut" :aria-label="started ? \'Scholar9 review\' : \'Start Scholar9 review\'">' +
                '<strong>Scholar9 Transparent Peer Review</strong>' +
                '<p v-if="started">Review status: <span class="s9-capitalize">{{ status || \'active\' }}</span></p>' +
                '<p v-else>Send this submission to verified Scholar9 reviewers.</p>' +
                '<button type="button" class="s9-button s9-button--primary" @click="onOpen">' +
                    '{{ started ? \'Open Scholar9 chat\' : \'Review with Scholar9\' }}' +
                '</button>' +
            '</section>'
    };

    var Scholar9WorkflowWorkspace = {
        name: WORKSPACE_COMPONENT,
        props: {
            submissionId: {type: Number, required: true},
            reviewRoundId: {type: Number, default: 0},
            namespace: {type: String, required: true}
        },
        data: function () {
            return {
                loading: true,
                operation: '',
                error: '',
                notice: '',
                status: 'not_started',
                record: null,
                files: [],
                currentReviewerId: '',
                messageText: '',
                publisherRatings: [0, 0, 0, 0, 0],
                publisherRatingComment: '',
                controllers: [],
                epoch: 0,
                manuscriptFileId: '',
                supplementaryFileIds: [],
                plagiarismFileId: '',
                parameters: [],
                selectedParameterIds: [],
                reviewerQuery: '',
                reviewerPool: 'all',
                reviewerResults: [],
                selectedReviewerIds: [],
                selectedReviewerDetails: {},
                reviewerSearchBusy: false,
                showReviewerChange: false,
                changeReviewerQuery: '',
                changeReviewerResults: [],
                changeReviewerBusy: false,
                selectedFileId: '',
                failedPhotoUrls: [],
                autoSyncTimer: null,
                autoSyncBusy: false,
                visibilityHandler: null,
                rateLimitedUntil: 0,
                rateLimitCountdown: 0,
                rateLimitTimer: null
            };
        },
        computed: {
            configured: function () {
                return !!config.configured;
            },
            settingsUrl: function () {
                return stringValue(config.settingsUrl) || '#';
            },
            isStarted: function () {
                return !!(this.record && this.record.s9_paper_id);
            },
            isConversationClosed: function () {
                return [
                    'reviewer_completed',
                    'decision_returned',
                    'reviewer_declined',
                    'cancelled'
                ].indexOf(stringValue(this.status).toLowerCase()) !== -1;
            },
            isCurrentConversationClosed: function () {
                var reviewerStatus = stringValue(
                    this.currentReviewer && this.currentReviewer.status
                ).toLowerCase();
                return this.isConversationClosed || [
                    'reviewer_completed',
                    'decision_returned',
                    'reviewer_declined',
                    'cancelled'
                ].indexOf(reviewerStatus) !== -1;
            },
            reviewers: function () {
                return normalizeReviewers(this.record);
            },
            currentReviewer: function () {
                var selectedId = stringValue(this.currentReviewerId);
                return this.reviewers.find(function (reviewer) {
                    return reviewer.id === selectedId;
                }) || this.reviewers[0] || null;
            },
            messages: function () {
                var selectedId = this.currentReviewer ? this.currentReviewer.id : '';
                var all = arrayValue(this.record && this.record.messages_cache);
                var hasThreadIds = all.some(function (message) {
                    return !!messageReviewerId(message);
                });
                if (!hasThreadIds || !selectedId) {
                    return all;
                }
                return all.filter(function (message) {
                    var threadReviewerId = messageReviewerId(message);
                    return !threadReviewerId || threadReviewerId === selectedId;
                });
            },
            canCreate: function () {
                return !!this.manuscriptFileId
                    && this.selectedParameterIds.length > 0
                    && this.selectedReviewerIds.length > 0
                    && !this.operation
                    && !this.rateLimited;
            },
            rateLimited: function () {
                return this.rateLimitCountdown > 0;
            },
            selectedSetupReviewers: function () {
                var self = this;
                return this.selectedReviewerIds.map(function (id) {
                    return self.selectedReviewerDetails[id];
                }).filter(Boolean);
            },
            scholar9Origin: function () {
                return safeHttpUrl(config.scholar9Origin).replace(/\/$/, '');
            },
            openReviewUrl: function () {
                if (!this.currentReviewerOpenReview || !this.currentReviewer || !this.currentReviewer.slug || !this.scholar9Origin) {
                    return '';
                }
                return this.scholar9Origin + '/open-review/' + encodeURIComponent(this.record.s9_paper_id) + '/' + encodeURIComponent(this.currentReviewer.slug);
            },
            currentReviewerOpenReview: function () {
                if (!this.currentReviewer) {
                    return false;
                }
                if (this.currentReviewer.open_review) {
                    return true;
                }
                return this.reviewers.length === 1 && !!(this.record && this.record.open_review);
            },
            fullReviewUrl: function () {
                if (!this.record || !this.record.full_review || !this.scholar9Origin) {
                    return '';
                }
                return this.scholar9Origin + '/open-paper-link/' + encodeURIComponent(this.record.s9_paper_id);
            },
            maxReviewers: function () {
                return Math.max(1, Number(config.maxReviewers) || 5);
            },
            maxMessageLength: function () {
                return Math.max(1, Number(config.maxMessageLength) || 5000);
            },
            currentReviewerHasRating: function () {
                var self = this;
                return this.messages.some(function (message) {
                    return !self.isPublisherMessage(message) && self.messageRatingValues(message).length > 0;
                });
            },
            publisherRatingSubmitted: function () {
                var self = this;
                return this.messages.some(function (message) {
                    return self.isPublisherMessage(message) && self.messageRatingValues(message).length > 0;
                });
            },
            openReviewEligible: function () {
                return !!this.currentReviewer && this.hasBothRatings(this.currentReviewer);
            },
            fullReviewEligible: function () {
                var self = this;
                return this.reviewers.length > 0 && this.reviewers.every(function (reviewer) {
                    return self.hasBothRatings(reviewer);
                });
            },
            canSubmitPublisherRating: function () {
                return this.isCurrentConversationClosed
                    && this.currentReviewerHasRating
                    && !this.publisherRatingSubmitted
                    && this.publisherRatings.length === 5
                    && this.publisherRatings.every(function (rating) {
                        return Number(rating) >= 1 && Number(rating) <= 5;
                    })
                    && !this.operation
                    && !this.rateLimited;
            }
        },
        watch: {
            submissionId: {
                immediate: true,
                handler: function () {
                    this.resetAndLoad();
                }
            },
            reviewRoundId: function () {
                this.resetAndLoad();
            },
            reviewers: function (reviewers) {
                if (!reviewers.length) {
                    this.currentReviewerId = '';
                    return;
                }
                var exists = reviewers.some(function (reviewer) {
                    return reviewer.id === this.currentReviewerId;
                }, this);
                if (!exists) {
                    this.currentReviewerId = reviewers[0].id;
                }
            }
        },
        mounted: function () {
            var self = this;
            this.visibilityHandler = function () {
                if (!document.hidden) {
                    self.pollStatus();
                }
            };
            document.addEventListener('visibilitychange', this.visibilityHandler);
            this.startAutoSync();
        },
        beforeUnmount: function () {
            this.stopAutoSync();
            if (this.rateLimitTimer) {
                window.clearInterval(this.rateLimitTimer);
                this.rateLimitTimer = null;
            }
            if (this.visibilityHandler) {
                document.removeEventListener('visibilitychange', this.visibilityHandler);
                this.visibilityHandler = null;
            }
            this.abortRequests();
            this.closeFileDialog();
        },
        methods: {
            startAutoSync: function () {
                var self = this;
                this.stopAutoSync();
                if (!config.autoSync) {
                    return;
                }
                this.autoSyncTimer = window.setInterval(function () {
                    self.pollStatus();
                }, Math.max(15000, Number(config.autoSyncInterval) || 20000));
            },
            stopAutoSync: function () {
                if (this.autoSyncTimer) {
                    window.clearInterval(this.autoSyncTimer);
                    this.autoSyncTimer = null;
                }
            },
            pollStatus: function () {
                var self = this;
                var epoch = this.epoch;
                var submissionId = Number(this.submissionId);
                if (!config.autoSync || document.hidden || this.loading || this.operation
                    || this.autoSyncBusy || !this.isStarted || this.rateLimitCountdown > 0) {
                    return;
                }
                this.autoSyncBusy = true;
                statusRequest(submissionId, this.reviewRoundId)
                    .then(function (payload) {
                        if (self.epoch !== epoch || Number(self.submissionId) !== submissionId) {
                            return;
                        }
                        self.assertRecordScope(payload.record);
                        if (payload.record) {
                            self.setRecord(payload.record);
                        }
                    })
                    .catch(function () {
                        // Background refresh failures stay silent. Manual sync
                        // remains available and reports actionable errors.
                    })
                    .finally(function () {
                        if (self.epoch === epoch) {
                            self.autoSyncBusy = false;
                        }
                    });
            },
            newController: function () {
                var controller = new AbortController();
                this.controllers.push(controller);
                return controller;
            },
            releaseController: function (controller) {
                this.controllers = this.controllers.filter(function (item) {
                    return item !== controller;
                });
            },
            abortRequests: function () {
                this.controllers.forEach(function (controller) {
                    controller.abort();
                });
                this.controllers = [];
            },
            resetAndLoad: function () {
                this.abortRequests();
                this.closeFileDialog();
                this.epoch += 1;
                this.loading = true;
                this.operation = '';
                this.error = '';
                this.notice = '';
                this.status = 'not_started';
                this.record = null;
                this.files = [];
                this.currentReviewerId = '';
                this.messageText = '';
                this.publisherRatings = [0, 0, 0, 0, 0];
                this.publisherRatingComment = '';
                this.manuscriptFileId = '';
                this.supplementaryFileIds = [];
                this.plagiarismFileId = '';
                this.parameters = [];
                this.selectedParameterIds = [];
                this.reviewerQuery = '';
                this.reviewerResults = [];
                this.selectedReviewerIds = [];
                this.selectedReviewerDetails = {};
                this.showReviewerChange = false;
                this.changeReviewerQuery = '';
                this.changeReviewerResults = [];
                this.selectedFileId = '';
                this.failedPhotoUrls = [];
                this.loadStatus();
            },
            loadStatus: function () {
                var self = this;
                var epoch = this.epoch;
                var submissionId = Number(this.submissionId);
                var controller = this.newController();
                statusRequest(submissionId, this.reviewRoundId, controller.signal)
                    .then(function (payload) {
                        if (self.epoch !== epoch || Number(self.submissionId) !== submissionId) {
                            return;
                        }
                        self.assertRecordScope(payload.record);
                        self.record = payload.record || null;
                        self.status = stringValue(payload.status || 'not_started');
                        self.files = arrayValue(payload.files).map(function (file) {
                            return {
                                id: Number(file.id),
                                name: stringValue(file.name) || 'Submission file',
                                mimeType: stringValue(file.mimeType),
                                size: Number(file.size) || 0
                            };
                        });
                        if (self.files.length) {
                            self.manuscriptFileId = String(self.files[0].id);
                            self.selectedFileId = String(self.files[0].id);
                        }
                        if (self.isStarted) {
                            var reviewers = normalizeReviewers(self.record);
                            self.currentReviewerId = stringValue(
                                self.record.reviewer_id || (reviewers[0] && reviewers[0].id)
                            );
                        } else if (config.configured) {
                            self.loadParameters();
                            self.reviewerQuery = 're';
                            self.searchSetupReviewers();
                        }
                    })
                    .catch(function (error) {
                        if (error.name !== 'AbortError' && self.epoch === epoch) {
                            self.error = error.message;
                        }
                    })
                    .finally(function () {
                        self.releaseController(controller);
                        if (self.epoch === epoch) {
                            self.loading = false;
                        }
                    });
            },
            assertRecordScope: function (record) {
                if (record && Number(record.submission_id) !== Number(this.submissionId)) {
                    throw new Error('OJS rejected Scholar9 data from a different submission.');
                }
            },
            runAction: function (operation, values) {
                var self = this;
                var epoch = this.epoch;
                var submissionId = Number(this.submissionId);
                var controller = this.newController();
                return actionRequest(
                    operation,
                    submissionId,
                    this.reviewRoundId,
                    values || {},
                    controller.signal
                ).then(function (payload) {
                    if (self.epoch !== epoch || Number(self.submissionId) !== submissionId) {
                        throw new DOMException('Stale Scholar9 response', 'AbortError');
                    }
                    if (payload.record) {
                        self.assertRecordScope(payload.record);
                    }
                    return payload;
                }).catch(function (error) {
                    if (error.name !== 'AbortError') {
                        self.registerRateLimit(error);
                    }
                    throw error;
                }).finally(function () {
                    self.releaseController(controller);
                });
            },
            registerRateLimit: function (error) {
                var seconds = Number(error && error.retryAfter) || 0;
                if (!seconds && /rate limit/i.test(stringValue(error && error.message))) {
                    seconds = 30;
                }
                if (seconds > 0) {
                    this.applyRateLimit(seconds);
                }
            },
            applyRateLimit: function (seconds) {
                var self = this;
                var until = Date.now() + Math.max(1, seconds) * 1000;
                if (until <= this.rateLimitedUntil) {
                    return;
                }
                this.rateLimitedUntil = until;
                this.rateLimitCountdown = Math.ceil(seconds);
                if (this.rateLimitTimer) {
                    window.clearInterval(this.rateLimitTimer);
                }
                this.rateLimitTimer = window.setInterval(function () {
                    var remaining = Math.ceil((self.rateLimitedUntil - Date.now()) / 1000);
                    if (remaining <= 0) {
                        self.rateLimitCountdown = 0;
                        window.clearInterval(self.rateLimitTimer);
                        self.rateLimitTimer = null;
                        return;
                    }
                    self.rateLimitCountdown = remaining;
                }, 500);
            },
            setRecord: function (record) {
                this.assertRecordScope(record);
                this.record = record || null;
                this.status = stringValue(record && record.status) || this.status;
            },
            clearFeedback: function () {
                this.error = '';
                this.notice = '';
            },
            loadParameters: function () {
                var self = this;
                return this.runAction('parameters', {}).then(function (payload) {
                    self.parameters = arrayValue(payload.parameters).map(function (parameter) {
                        return {
                            id: stringValue(parameter.id || parameter._id || parameter.key),
                            label: stringValue(parameter.name || parameter.title || parameter.label) || 'Review parameter'
                        };
                    }).filter(function (parameter) { return parameter.id; });
                    self.selectedParameterIds = self.parameters.map(function (parameter) {
                        return parameter.id;
                    });
                }).catch(function (error) {
                    if (error.name !== 'AbortError') {
                        self.error = error.message;
                    }
                });
            },
            searchSetupReviewers: function () {
                var self = this;
                var query = this.reviewerQuery.trim();
                if (query.length < 2) {
                    this.error = 'Enter at least two characters to search reviewers.';
                    return;
                }
                this.clearFeedback();
                this.reviewerSearchBusy = true;
                this.runAction('search_reviewers', {q: query, pool: this.reviewerPool})
                    .then(function (payload) {
                        self.reviewerResults = arrayValue(payload.reviewers)
                            .map(normalizeReviewerResult)
                            .filter(function (reviewer) { return reviewer.id; });
                    })
                    .catch(function (error) {
                        if (error.name !== 'AbortError') {
                            self.error = error.message;
                        }
                    })
                    .finally(function () {
                        self.reviewerSearchBusy = false;
                    });
            },
            isSetupReviewerSelected: function (reviewer) {
                return this.selectedReviewerIds.indexOf(reviewer.id) !== -1;
            },
            toggleSetupReviewer: function (reviewer) {
                var selectedIndex = this.selectedReviewerIds.indexOf(reviewer.id);
                if (selectedIndex !== -1) {
                    this.selectedReviewerIds.splice(selectedIndex, 1);
                    delete this.selectedReviewerDetails[reviewer.id];
                    this.selectedReviewerDetails = Object.assign({}, this.selectedReviewerDetails);
                    return;
                }
                if (this.selectedReviewerIds.length >= this.maxReviewers) {
                    this.error = 'You can select up to ' + this.maxReviewers + ' reviewers.';
                    return;
                }
                this.error = '';
                this.selectedReviewerIds.push(reviewer.id);
                this.selectedReviewerDetails[reviewer.id] = reviewer;
                this.selectedReviewerDetails = Object.assign({}, this.selectedReviewerDetails);
            },
            createReview: function () {
                var self = this;
                if (!this.canCreate) {
                    this.error = 'Select a manuscript, at least one review parameter, and at least one reviewer.';
                    return;
                }
                var fileIds = [String(this.manuscriptFileId)].concat(
                    this.supplementaryFileIds.filter(function (id) {
                        return String(id) !== String(self.manuscriptFileId);
                    })
                );
                this.clearFeedback();
                this.operation = 'create';
                this.runAction('create', {
                    fileIds: fileIds.join(','),
                    plagiarismFileId: this.plagiarismFileId,
                    parameterIds: this.selectedParameterIds.join(','),
                    reviewerIds: this.selectedReviewerIds.join(',')
                }).then(function (payload) {
                    self.setRecord(payload.record);
                    self.currentReviewerId = self.reviewers.length ? self.reviewers[0].id : '';
                    self.notice = payload.warning || 'The submission was sent to Scholar9.';
                }).catch(function (error) {
                    if (error.name !== 'AbortError') {
                        self.error = error.message;
                    }
                }).finally(function () {
                    self.operation = '';
                });
            },
            chooseConversation: function (reviewer) {
                this.currentReviewerId = reviewer.id;
                this.messageText = '';
                this.publisherRatings = [0, 0, 0, 0, 0];
                this.publisherRatingComment = '';
                this.error = '';
            },
            syncConversation: function () {
                var self = this;
                this.clearFeedback();
                this.operation = 'sync';
                this.runAction('sync', {}).then(function (payload) {
                    self.setRecord(payload.record);
                    self.notice = 'Scholar9 conversation synchronized.';
                }).catch(function (error) {
                    if (error.name !== 'AbortError') {
                        self.error = error.message;
                    }
                }).finally(function () {
                    self.operation = '';
                });
            },
            sendMessage: function () {
                var self = this;
                var reviewer = this.currentReviewer;
                var text = this.messageText.trim();
                if (!reviewer) {
                    this.error = 'Select a reviewer before sending a message.';
                    return;
                }
                if (!text) {
                    this.error = 'Enter a message.';
                    return;
                }
                if (text.length > this.maxMessageLength) {
                    this.error = 'The message must not exceed ' + this.maxMessageLength + ' characters.';
                    return;
                }
                this.clearFeedback();
                this.operation = 'send_message';
                this.runAction('send_message', {text: text, reviewerId: reviewer.id})
                    .then(function (payload) {
                        if (payload.record) {
                            self.setRecord(payload.record);
                        } else {
                            self.appendOptimisticMessage({
                                from: 'editor',
                                text: stringValue(payload.sanitized_text || text),
                                reviewer_id: reviewer.id,
                                reviewer_name: reviewer.name,
                                created_at: new Date().toISOString()
                            });
                        }
                        self.messageText = '';
                        self.notice = 'Message sent to ' + reviewer.name + '.';
                    })
                    .catch(function (error) {
                        if (error.name !== 'AbortError') {
                            self.error = error.message;
                        }
                    })
                    .finally(function () {
                        self.operation = '';
                    });
            },
            setPublisherRating: function (index, value) {
                var ratings = this.publisherRatings.slice();
                ratings[index] = Math.max(1, Math.min(5, Number(value) || 0));
                this.publisherRatings = ratings;
            },
            submitPublisherRating: function () {
                var self = this;
                var reviewer = this.currentReviewer;
                if (!reviewer || !this.canSubmitPublisherRating) {
                    this.error = 'Rate all five reviewer performance criteria before submitting.';
                    return;
                }
                var comment = this.publisherRatingComment.trim();
                if (comment.length > 2000) {
                    this.error = 'Rating feedback must not exceed 2000 characters.';
                    return;
                }
                this.clearFeedback();
                this.operation = 'rate_reviewer';
                this.runAction('rate_reviewer', {
                    reviewerId: reviewer.id,
                    ratings: this.publisherRatings.join(','),
                    comment: comment
                }).then(function (payload) {
                    self.setRecord(payload.record);
                    self.publisherRatingComment = '';
                    self.notice = 'Your reviewer rating was sent to Scholar9.';
                }).catch(function (error) {
                    if (error.name !== 'AbortError') {
                        self.error = error.message;
                    }
                }).finally(function () {
                    self.operation = '';
                });
            },
            appendOptimisticMessage: function (message) {
                var record = Object.assign({}, this.record || {});
                record.messages_cache = arrayValue(record.messages_cache).concat([message]);
                this.record = record;
            },
            openFileDialog: function () {
                this.clearFeedback();
                if (!this.currentReviewer) {
                    this.error = 'Select a reviewer before sending a file.';
                    return;
                }
                if (!this.files.length) {
                    this.error = 'No submission files are available.';
                    return;
                }
                this.selectedFileId = String(this.files[0].id);
                var self = this;
                this.$nextTick(function () {
                    if (self.$refs.fileDialog && !self.$refs.fileDialog.open) {
                        self.$refs.fileDialog.showModal();
                    }
                });
            },
            closeFileDialog: function () {
                var dialog = this.$refs && this.$refs.fileDialog;
                if (dialog && dialog.open) {
                    dialog.close();
                }
            },
            sendSelectedFile: function () {
                var self = this;
                var reviewer = this.currentReviewer;
                var file = this.files.find(function (candidate) {
                    return String(candidate.id) === String(self.selectedFileId);
                });
                if (!reviewer || !file) {
                    this.error = 'Select a valid file and reviewer.';
                    return;
                }
                this.clearFeedback();
                this.operation = 'send_file';
                this.runAction('send_file', {fileId: file.id, reviewerId: reviewer.id})
                    .then(function (payload) {
                        if (payload.record) {
                            self.setRecord(payload.record);
                        } else {
                            self.appendOptimisticMessage({
                                from: 'editor',
                                text: 'File shared: ' + stringValue(payload.attachment_name || file.name),
                                attachment_name: stringValue(payload.attachment_name || file.name),
                                attachment_url: stringValue(payload.attachment_url),
                                reviewer_id: reviewer.id,
                                reviewer_name: reviewer.name,
                                created_at: new Date().toISOString()
                            });
                        }
                        self.closeFileDialog();
                        self.notice = 'File sent to ' + reviewer.name + '.';
                    })
                    .catch(function (error) {
                        if (error.name !== 'AbortError') {
                            self.error = error.message;
                        }
                    })
                    .finally(function () {
                        self.operation = '';
                    });
            },
            toggleReviewerChange: function () {
                this.showReviewerChange = !this.showReviewerChange;
                this.changeReviewerResults = [];
                this.changeReviewerQuery = '';
                this.error = '';
            },
            searchChangeReviewers: function () {
                var self = this;
                var query = this.changeReviewerQuery.trim();
                if (query.length < 2) {
                    this.error = 'Enter at least two characters to search reviewers.';
                    return;
                }
                this.clearFeedback();
                this.changeReviewerBusy = true;
                this.runAction('search_reviewers', {q: query, pool: 'all'})
                    .then(function (payload) {
                        self.changeReviewerResults = arrayValue(payload.reviewers)
                            .map(normalizeReviewerResult)
                            .filter(function (reviewer) { return reviewer.id; });
                    })
                    .catch(function (error) {
                        if (error.name !== 'AbortError') {
                            self.error = error.message;
                        }
                    })
                    .finally(function () {
                        self.changeReviewerBusy = false;
                    });
            },
            assignReviewer: function (reviewer) {
                var self = this;
                this.clearFeedback();
                this.operation = 'change_reviewer';
                this.runAction('change_reviewer', {
                    reviewerId: reviewer.id,
                    reviewerName: reviewer.name
                }).then(function (payload) {
                    self.setRecord(payload.record);
                    self.currentReviewerId = reviewer.id;
                    self.showReviewerChange = false;
                    self.notice = payload.warning || (reviewer.name + ' is now assigned to this review.');
                }).catch(function (error) {
                    if (error.name !== 'AbortError') {
                        self.error = error.message;
                    }
                }).finally(function () {
                    self.operation = '';
                });
            },
            toggleOpenReview: function () {
                var self = this;
                this.clearFeedback();
                this.operation = 'toggle_open';
                this.runAction('toggle_open', {
                    reviewerId: this.currentReviewer ? this.currentReviewer.id : ''
                }).then(function (payload) {
                    self.setRecord(payload.record || self.record);
                    self.notice = payload.open_review ? 'Open review enabled.' : 'Open review disabled.';
                }).catch(function (error) {
                    if (error.name !== 'AbortError') {
                        self.error = error.message;
                    }
                }).finally(function () {
                    self.operation = '';
                });
            },
            toggleFullReview: function () {
                var self = this;
                this.clearFeedback();
                this.operation = 'toggle_full_review';
                this.runAction('toggle_full_review', {}).then(function (payload) {
                    self.setRecord(payload.record || Object.assign({}, self.record || {}, {full_review: payload.full_review ? 1 : 0}));
                    self.notice = payload.full_review ? 'Full review enabled.' : 'Full review disabled.';
                }).catch(function (error) {
                    if (error.name !== 'AbortError') { self.error = error.message; }
                }).finally(function () {
                    self.operation = '';
                });
            },
            isPublisherMessage: function (message) {
                var sender = stringValue(message && (message.from || message.sender_type || message.role)).toLowerCase();
                return sender === 'editor' || sender === 'publisher' || sender === 'ojs';
            },
            hasBothRatings: function (reviewer) {
                var self = this;
                var allMessages = arrayValue(this.record && this.record.messages_cache);
                if (!allMessages.length) { allMessages = this.messages; }
                var thread = allMessages.filter(function (message) {
                    return messageReviewerId(message) === reviewerId(reviewer);
                });
                return thread.some(function (message) { return !self.isPublisherMessage(message) && self.messageRatingValues(message).length > 0; })
                    && thread.some(function (message) { return self.isPublisherMessage(message) && self.messageRatingValues(message).length > 0; });
            },
            isRevisionMessage: function (message) {
                var raw = message && (
                    message.comment_status !== null && typeof message.comment_status !== 'undefined'
                        ? message.comment_status
                        : message.status
                );
                return stringValue(raw).trim() === '2';
            },
            revisionTone: function (message) {
                if (!this.isRevisionMessage(message)) { return ''; }
                var text = stringValue(message && (message.text || message.message || message.body || message.content));
                var pattern = /Revision\s*Status\s*:\s*(Minor\s+Revision|Major\s+Revision|Clarification)\b/ig;
                var match;
                var lastStatus = '';
                while ((match = pattern.exec(text)) !== null) {
                    lastStatus = stringValue(match[1]).toLowerCase().replace(/\s+/g, ' ').trim();
                }
                if (lastStatus === 'major revision') { return 'major'; }
                if (lastStatus === 'minor revision') { return 'minor'; }
                if (lastStatus === 'clarification') { return 'clarification'; }
                return '';
            },
            messageTextValue: function (message) {
                var text = stringValue(message && (message.text || message.message || message.body || message.content));
                if (!text) {
                    var status = stringValue(message && (
                        message.status || message.comment_status || message.event || message.action
                    )).trim().toLowerCase().replace(/[ -]+/g, '_');
                    var statusLabels = {
                        '1': 'Review Request Accepted',
                        '2': 'Revision Requested',
                        '3': 'Review Completed',
                        '4': 'Review Request Rejected',
                        accepted: 'Review Request Accepted',
                        accept: 'Review Request Accepted',
                        review_accepted: 'Review Request Accepted',
                        request_accepted: 'Review Request Accepted',
                        review_request_accepted: 'Review Request Accepted',
                        major_revision: 'Major Revision Requested',
                        minor_revision: 'Minor Revision Requested',
                        revision_required: 'Revision Requested',
                        revision_requested: 'Revision Requested',
                        reviewer_completed: 'Review Completed',
                        review_completed: 'Review Completed',
                        completed: 'Review Completed',
                        reviewer_declined: 'Review Request Declined',
                        request_declined: 'Review Request Declined',
                        declined: 'Review Request Declined'
                    };
                    text = statusLabels[status] || (status ? 'Review Status Updated' : '');
                }
                if (this.messageAttachment(message) && /^(?:shared file|file shared)\s*:/i.test(text)) {
                    return '';
                }
                text = text.replace(/(?:^|\r?\n)\s*Rating\s*:\s*[1-5](?:\s*,\s*[1-5]){0,9}\s*$/i, '').trim();
                return text;
            },
            reviewerDisplayStatus: function (reviewer) {
                var self = this;
                var cached = arrayValue(this.record && this.record.messages_cache);
                var source = cached.length ? cached : arrayValue(this.messages);
                var messages = source.filter(function (message) {
                    return messageReviewerId(message) === reviewerId(reviewer) && self.isRevisionMessage(message);
                });
                if (messages.length) {
                    var latest = messages.reduce(function (candidate, message) {
                        if (!candidate) { return message; }
                        return messageTimestamp(message) >= messageTimestamp(candidate) ? message : candidate;
                    }, null);
                    var tone = self.revisionTone(latest);
                    var revision = tone === 'clarification' ? 'Clarification' : tone === 'major' ? 'Major Revision' : tone === 'minor' ? 'Minor Revision' : 'Revision Requested';
                    var base = self.statusLabel(reviewer && reviewer.status);
                    return base && base.toLowerCase() !== revision.toLowerCase() ? base + ' / ' + revision : revision;
                }
                return self.statusLabel(reviewer && reviewer.status);
            },
            ratingCriteria: function (index) {
                return [
                    'Quality of review',
                    'Constructive feedback',
                    'Subject expertise',
                    'Clarity and professionalism',
                    'Timeliness'
                ][index] || ('Criterion ' + (index + 1));
            },
            messageRatingCriteria: function (message, index) {
                if (this.isPublisherMessage(message)) {
                    return this.ratingCriteria(index);
                }
                return [
                    'Relevance and Originality',
                    'Methodology',
                    'Validity & Reliability',
                    'Clarity and Structure',
                    'Results and Analysis'
                ][index] || ('Review criterion ' + (index + 1));
            },
            messageRatingValues: function (message) {
                var structured = arrayValue(message && message.rating_data)
                    .map(function (rating) { return Number(rating); })
                    .filter(function (rating) { return rating >= 1 && rating <= 5; });
                if (structured.length) {
                    return structured.slice(0, 5);
                }
                var text = stringValue(message && (message.text || message.message || message.body || message.content));
                var match = text.match(/(?:^|\r?\n)\s*Rating\s*:\s*([1-5](?:\s*,\s*[1-5]){0,9})\s*$/i);
                return match ? match[1].split(',').map(function (rating) {
                    return Number(rating.trim());
                }).slice(0, 5) : [];
            },
            messageAttachment: function (message) {
                var name = stringValue(message && (
                    message.attachment_name || message.file_name || (message.attachment && message.attachment.name)
                ));
                var url = safeHttpUrl(message && (
                    message.attachment_url || message.file_url || (message.attachment && message.attachment.url)
                ));
                var text = stringValue(message && (message.text || message.message || message.body || message.content));
                var isFileMessage = /^(?:shared file|file shared)\s*:/i.test(text);
                if (!url && isFileMessage) {
                    var match = text.match(/https?:\/\/[^\s<]+/i);
                    url = safeHttpUrl(match && match[0]);
                }
                if (!name && isFileMessage) {
                    name = text.replace(/^(?:shared file|file shared)\s*:\s*/i, '').split(/\r?\n/)[0].trim();
                }
                if (!url && !name) {
                    return null;
                }
                return {name: name || 'File shared', url: url};
            },
            messageSender: function (message) {
                var publisherMessage = this.isPublisherMessage(message);
                var fallbackRole = publisherMessage ? 'Publisher' : 'Reviewer';
                var rawRole = stringValue(message && (
                    message.sender_role || message.designation || message.role
                )).trim().toLowerCase();
                var roles = {
                    reviewer: 'Reviewer',
                    publisher: 'Publisher',
                    author: 'Author',
                    'journal manager': 'Journal Manager',
                    journal_manager: 'Journal Manager',
                    editor: 'Editor'
                };
                var role = roles[rawRole] || fallbackRole;
                var name = stringValue(message && message.sender_name).trim();
                if (!name && !publisherMessage) {
                    name = stringValue(message && message.reviewer_name).trim()
                        || (this.currentReviewer ? this.currentReviewer.name : '');
                }
                if (!name || name.toLowerCase() === role.toLowerCase()) {
                    return role;
                }
                return name + ' (' + role + ')';
            },
            messageDate: function (message) {
                var value = stringValue(message && (
                    message.created_at || message.sent_at || message.date || message.timestamp
                ));
                if (!value) {
                    return '';
                }
                var date = new Date(value);
                return isNaN(date.getTime()) ? value : date.toLocaleString();
            },
            initials: function (name) {
                var parts = stringValue(name).trim().split(/\s+/).filter(Boolean);
                return (parts.slice(0, 2).map(function (part) {
                    return part.charAt(0).toUpperCase();
                }).join('') || 'R').slice(0, 2);
            },
            hideReviewerPhoto: function (reviewer) {
                var url = stringValue(reviewer && reviewer.photoUrl);
                if (url && this.failedPhotoUrls.indexOf(url) === -1) {
                    this.failedPhotoUrls.push(url);
                }
            },
            reviewerPhotoUrl: function (reviewer) {
                var url = stringValue(reviewer && reviewer.photoUrl);
                return url && this.failedPhotoUrls.indexOf(url) === -1 ? url : '';
            },
            statusLabel: function (value) {
                return reviewStatusLabel(value);
            },
            statusDotClass: function (value) {
                return 's9-review-status--' + reviewStatusTone(value);
            },
            statusBoxClass: function (value) {
                return 's9-reviewer-tone--' + reviewStatusTone(value);
            },
            formatBytes: function (bytes) {
                var size = Number(bytes) || 0;
                if (!size) {
                    return '';
                }
                if (size < 1024) {
                    return size + ' B';
                }
                if (size < 1048576) {
                    return Math.round(size / 1024) + ' KB';
                }
                return (size / 1048576).toFixed(1) + ' MB';
            }
        },
        template:
            '<section class="s9-workflow" :data-submission-id="submissionId" :aria-busy="loading || !!operation">' +
                '<div v-if="loading" class="s9-state s9-state--loading" role="status">' +
                    '<span class="s9-spinner" aria-hidden="true"></span><span>Loading Scholar9 review…</span>' +
                '</div>' +
                '<template v-else>' +
                    '<div v-if="error" class="s9-alert s9-alert--error" role="alert">' +
                        '<span>{{ error }}</span><button type="button" @click="error = \'\'" aria-label="Dismiss error">×</button>' +
                    '</div>' +
                    '<div v-if="notice" class="s9-alert s9-alert--success" role="status">' +
                        '<span>{{ notice }}</span><button type="button" @click="notice = \'\'" aria-label="Dismiss message">×</button>' +
                    '</div>' +
                    '<div v-if="!configured" class="s9-state s9-state--configuration">' +
                        '<h2>Scholar9 is not configured</h2>' +
                        '<p>Connect this journal before selecting reviewers or synchronizing conversations.</p>' +
                        '<ol class="s9-setup-steps">' +
                            '<li><strong>Open plugin settings:</strong> go to Settings &gt; Website &gt; Plugins, expand Scholar9 Transparent Peer Review, and choose Settings.</li>' +
                            '<li><strong>Request credentials:</strong> enter the Scholar9 Base URL and save. Then use Sync to Scholar9 beside the plugin to submit this journal for API access.</li>' +
                            '<li><strong>Approve the request:</strong> a Scholar9 administrator selects a plan and approves the pending API request. The Client ID, one-time Client Secret, and Journal Slug are then issued.</li>' +
                            '<li><strong>Finish setup:</strong> return to plugin Settings, enter the Client ID, Client Secret, and Journal Slug exactly as issued, then save.</li>' +
                        '</ol>' +
                        '<p class="s9-security-note">Keep the client secret private. Do not paste it into a submission, message, or support screenshot.</p>' +
                        '<a class="s9-button s9-button--primary" :href="settingsUrl">Open plugin settings</a>' +
                    '</div>' +
                    '<div v-else-if="!isStarted" class="s9-setup">' +
                        '<header class="s9-section-heading">' +
                            '<div><h2>Set up Scholar9 review</h2><p>Select submission files, review criteria, and up to {{ maxReviewers }} verified reviewers.</p></div>' +
                            '<span class="s9-badge">Submission {{ submissionId }}</span>' +
                        '</header>' +
                        '<div class="s9-setup-section">' +
                            '<div class="s9-section-title"><h3>Manuscript files</h3><span>Required</span></div>' +
                            '<p v-if="!files.length" class="s9-empty">No submission files are available. Upload a file in the Submission tab first.</p>' +
                            '<div v-else class="s9-file-options">' +
                                '<label v-for="file in files" :key="\'manuscript-\' + file.id" class="s9-file-option" :class="{\'is-selected\': String(file.id) === String(manuscriptFileId)}">' +
                                    '<input type="radio" :name="namespace + \'-manuscript\'" :value="String(file.id)" v-model="manuscriptFileId">' +
                                    '<span class="s9-file-icon" aria-hidden="true">DOC</span>' +
                                    '<span><strong>{{ file.name }}</strong><small>{{ file.mimeType }}<template v-if="file.size"> · {{ formatBytes(file.size) }}</template></small></span>' +
                                '</label>' +
                            '</div>' +
                            '<details v-if="files.length > 1" class="s9-details">' +
                                '<summary>Add supplementary files</summary>' +
                                '<label v-for="file in files" :key="\'supplement-\' + file.id" v-show="String(file.id) !== String(manuscriptFileId)" class="s9-check-row">' +
                                    '<input type="checkbox" :value="String(file.id)" v-model="supplementaryFileIds"> {{ file.name }}' +
                                '</label>' +
                            '</details>' +
                            '<label class="s9-field">' +
                                '<span>Plagiarism report <small>(optional)</small></span>' +
                                '<select v-model="plagiarismFileId"><option value="">No plagiarism report</option><option v-for="file in files" :key="\'plagiarism-\' + file.id" :value="String(file.id)">{{ file.name }}</option></select>' +
                            '</label>' +
                        '</div>' +
                        '<div class="s9-setup-section">' +
                            '<div class="s9-section-title"><h3>Review parameters</h3><span>Required</span></div>' +
                            '<p v-if="!parameters.length" class="s9-empty">Review parameters could not be loaded.</p>' +
                            '<div v-else class="s9-parameter-grid">' +
                                '<label v-for="parameter in parameters" :key="parameter.id" class="s9-check-row"><input type="checkbox" :value="parameter.id" v-model="selectedParameterIds"> {{ parameter.label }}</label>' +
                            '</div>' +
                        '</div>' +
                        '<div class="s9-setup-section">' +
                            '<div class="s9-section-title"><h3>Verified reviewers</h3><span>{{ selectedReviewerIds.length }} / {{ maxReviewers }} selected</span></div>' +
                            '<form class="s9-search" @submit.prevent="searchSetupReviewers">' +
                                '<label class="s9-field"><span>Search reviewers</span><input type="search" v-model="reviewerQuery" placeholder="Name or expertise" autocomplete="off"></label>' +
                                '<label class="s9-field s9-field--pool"><span>Reviewer pool</span><select v-model="reviewerPool"><option value="all">All reviewers</option></select></label>' +
                                '<button type="submit" class="s9-button" :disabled="reviewerSearchBusy">{{ reviewerSearchBusy ? \'Searching…\' : \'Search\' }}</button>' +
                            '</form>' +
                            '<div v-if="selectedSetupReviewers.length" class="s9-selected-reviewers">' +
                                '<button v-for="reviewer in selectedSetupReviewers" :key="\'selected-\' + reviewer.id" type="button" @click="toggleSetupReviewer(reviewer)">{{ reviewer.name }} <span aria-hidden="true">×</span></button>' +
                            '</div>' +
                            '<p v-if="!reviewerSearchBusy && !reviewerResults.length" class="s9-empty">Search Scholar9 to select reviewers.</p>' +
                            '<div v-else class="s9-reviewer-grid">' +
                                '<button v-for="reviewer in reviewerResults" :key="reviewer.id" type="button" class="s9-reviewer-card" :class="{\'is-selected\': isSetupReviewerSelected(reviewer)}" @click="toggleSetupReviewer(reviewer)" :aria-pressed="isSetupReviewerSelected(reviewer)">' +
                                    '<img v-if="reviewerPhotoUrl(reviewer)" :src="reviewerPhotoUrl(reviewer)" :alt="reviewer.name" @error="hideReviewerPhoto(reviewer)"><span v-else class="s9-avatar">{{ initials(reviewer.name) }}</span>' +
                                    '<strong>{{ reviewer.name }}</strong><small>{{ reviewer.designation || reviewer.expertise || \'Academic reviewer\' }}</small><span class="s9-stars" aria-label="Verified reviewer">★★★★★</span>' +
                                '</button>' +
                            '</div>' +
                        '</div>' +
                        '<footer class="s9-setup-footer">' +
                            '<span>{{ selectedReviewerIds.length }} reviewer{{ selectedReviewerIds.length === 1 ? \'\' : \'s\' }} selected</span>' +
                            '<button type="button" class="s9-button s9-button--primary" :disabled="!canCreate" @click="createReview">{{ operation === \'create\' ? \'Sending…\' : \'Send to Scholar9\' }}</button>' +
                        '</footer>' +
                    '</div>' +
                    '<div v-else class="s9-review-view">' +
                        '<div class="s9-full-review-toolbar"><div class="s9-toolbar-actions"><button v-if="!isConversationClosed" type="button" class="s9-toolbar-button" :disabled="rateLimited" @click="toggleReviewerChange"><span aria-hidden="true">&#128100;</span> Change reviewer</button><button type="button" class="s9-toolbar-button" :disabled="!!operation || rateLimited" @click="syncConversation"><span aria-hidden="true">&#8635;</span> {{ rateLimited ? \'Rate limited (\' + rateLimitCountdown + \'s)\' : \'Sync\' }}</button></div><div v-if="fullReviewEligible" class="s9-visibility-control"><span class="s9-visibility-label">Full review</span><button type="button" class="s9-toggle" :class="{\'is-on\': !!record.full_review}" role="switch" :aria-checked="!!record.full_review" :disabled="!!operation || rateLimited" @click="toggleFullReview"><span class="s9-toggle__track" aria-hidden="true"><i></i></span><span>{{ record.full_review ? \'ON\' : \'OFF\' }}</span></button><a v-if="fullReviewUrl" class="s9-public-link" :href="fullReviewUrl" target="_blank" rel="noopener noreferrer">View full review <span aria-hidden="true">&#8599;</span></a></div></div>' +
                    '<div class="s9-workspace">' +
                        '<aside class="s9-reviewer-pane">' +
                            '<div class="s9-pane-heading"><h2>Reviewers</h2><span class="s9-status-dot" :class="statusDotClass(status)" aria-hidden="true"></span></div>' +
                            '<div class="s9-reviewer-list">' +
                                '<button v-for="reviewer in reviewers" :key="reviewer.id" type="button" class="s9-reviewer-row" :class="[statusBoxClass(reviewer.status), {\'is-selected\': currentReviewer && reviewer.id === currentReviewer.id}]" @click="chooseConversation(reviewer)">' +
                                    '<img v-if="reviewerPhotoUrl(reviewer)" class="s9-avatar s9-avatar--photo" :src="reviewerPhotoUrl(reviewer)" :alt="reviewer.name" @error="hideReviewerPhoto(reviewer)"><span v-else class="s9-avatar">{{ initials(reviewer.name) }}</span><span class="s9-reviewer-row__identity"><strong>{{ reviewer.name }}</strong><small>{{ reviewerDisplayStatus(reviewer) }}</small></span><i class="s9-reviewer-status-dot" :class="statusDotClass(reviewer.status)" :title="reviewerDisplayStatus(reviewer)" aria-hidden="true"></i>' +
                                '</button>' +
                                '<p v-if="!reviewers.length" class="s9-empty">No reviewer assignment is available yet.</p>' +
                            '</div>' +
                            '<div v-if="showReviewerChange && !isConversationClosed" class="s9-change-reviewer">' +
                                '<form @submit.prevent="searchChangeReviewers"><label class="s9-field"><span>Find a reviewer</span><input type="search" v-model="changeReviewerQuery" placeholder="Name or expertise" autocomplete="off"></label><button type="submit" class="s9-button s9-button--block" :disabled="changeReviewerBusy">{{ changeReviewerBusy ? \'Searching…\' : \'Search Scholar9\' }}</button></form>' +
                                '<div class="s9-change-results"><button v-for="reviewer in changeReviewerResults" :key="\'change-\' + reviewer.id" type="button" @click="assignReviewer(reviewer)" :disabled="!!operation || rateLimited"><strong>{{ reviewer.name }}</strong><small>{{ reviewer.designation || reviewer.expertise || \'Academic reviewer\' }}</small></button></div>' +
                            '</div>' +
                            '<div class="s9-review-meta">' +
                                '<dl><div><dt>Status</dt><dd class="s9-capitalize">{{ currentReviewer ? reviewerDisplayStatus(currentReviewer) : statusLabel(status) }}</dd></div><div v-if="record.score"><dt>Score</dt><dd>{{ record.score }} / 5</dd></div><div v-if="record.recommendation"><dt>Recommendation</dt><dd class="s9-capitalize">{{ statusLabel(record.recommendation) }}</dd></div></dl>' +
                                '<div v-if="openReviewEligible" class="s9-visibility-control s9-visibility-control--sidebar"><span class="s9-visibility-label">Open review</span><button type="button" class="s9-toggle" :class="{\'is-on\': currentReviewerOpenReview}" role="switch" :aria-checked="currentReviewerOpenReview" :disabled="!!operation || !currentReviewer || rateLimited" @click="toggleOpenReview"><span class="s9-toggle__track" aria-hidden="true"><i></i></span><span>{{ currentReviewerOpenReview ? \'ON\' : \'OFF\' }}</span></button><a v-if="openReviewUrl" class="s9-public-link" :href="openReviewUrl" target="_blank" rel="noopener noreferrer">View reviewer page <span aria-hidden="true">&#8599;</span></a></div>' +
                            '</div>' +
                        '</aside>' +
                        '<main class="s9-chat-pane">' +
                            '<header class="s9-chat-heading" :class="currentReviewer ? statusBoxClass(currentReviewer.status) : \'\'"><div class="s9-chat-reviewer"><template v-if="currentReviewer"><img v-if="reviewerPhotoUrl(currentReviewer)" class="s9-avatar s9-avatar--photo" :src="reviewerPhotoUrl(currentReviewer)" :alt="currentReviewer.name" @error="hideReviewerPhoto(currentReviewer)"><span v-else class="s9-avatar">{{ initials(currentReviewer.name) }}</span></template><div><h2>{{ currentReviewer ? currentReviewer.name : \'Reviewer discussion\' }}</h2><p v-if="currentReviewer">{{ reviewerDisplayStatus(currentReviewer) }}</p></div></div><span v-if="currentReviewer" class="s9-status-badge" :class="statusDotClass(currentReviewer.status)"><i aria-hidden="true"></i>{{ reviewerDisplayStatus(currentReviewer) }}</span></header>' +
                            '<div class="s9-messages" role="log" aria-live="polite" aria-relevant="additions">' +
                                '<p v-if="!messages.length" class="s9-empty s9-empty--conversation">No messages in this conversation yet.</p>' +
                                '<article v-for="(message, index) in messages" :key="message.id || message.message_id || index" class="s9-message" :class="[{\'s9-message--publisher\': isPublisherMessage(message), \'s9-message--revision\': isRevisionMessage(message)}, revisionTone(message) ? \'s9-message--revision-\' + revisionTone(message) : \'\']">' +
                                    '<div><strong>{{ messageSender(message) }}</strong><time v-if="messageDate(message)">{{ messageDate(message) }}</time></div>' +
                                    '<p v-if="messageTextValue(message)" :class="{\'s9-revision-status\': isRevisionMessage(message)}"><span v-if="isRevisionMessage(message)" class="s9-revision-icon" title="Get a revision status request" aria-label="Revision status">&#9651;</span>{{ messageTextValue(message) }}</p>' +
                                    '<div v-if="messageRatingValues(message).length" class="s9-rating-summary" :aria-label="\'Rating details from \' + messageSender(message)">' +
                                        '<strong class="s9-rating-summary__title">Rating</strong>' +
                                        '<div v-for="(rating, ratingIndex) in messageRatingValues(message)" :key="\'rating-\' + ratingIndex" class="s9-rating-row">' +
                                            '<span>{{ messageRatingCriteria(message, ratingIndex) }}</span>' +
                                            '<span class="s9-star-display" :aria-label="rating + \' out of 5 stars\'"><i v-for="star in 5" :key="star" :class="{\'is-filled\': star <= rating}" aria-hidden="true">&#9733;</i><b>{{ rating }}/5</b></span>' +
                                        '</div>' +
                                    '</div>' +
                                    '<a v-if="messageAttachment(message) && messageAttachment(message).url" class="s9-attachment" :href="messageAttachment(message).url" target="_blank" rel="noopener noreferrer"><span aria-hidden="true">📎</span><span><em>File shared</em><strong>{{ messageAttachment(message).name }}</strong></span></a>' +
                                    '<div v-else-if="messageAttachment(message)" class="s9-attachment"><span aria-hidden="true">📎</span><span><em>File shared</em><strong>{{ messageAttachment(message).name }}</strong></span></div>' +
                                '</article>' +
                            '</div>' +
                            '<div v-if="isCurrentConversationClosed" class="s9-review-complete" role="status"><span aria-hidden="true">&#10003;</span><div><strong>Review submitted</strong><p>The reviewer conversation is closed.<template v-if="record.recommendation"> Recommendation: <b class="s9-capitalize">{{ statusLabel(record.recommendation) }}</b>.</template><template v-if="record.score"> Score: <b>{{ record.score }} / 5</b>.</template></p></div></div>' +
                            '<section v-if="isCurrentConversationClosed && currentReviewerHasRating && !publisherRatingSubmitted" class="s9-publisher-rating" aria-labelledby="s9-publisher-rating-title">' +
                                '<div class="s9-publisher-rating__heading"><div><h3 id="s9-publisher-rating-title">Rate this reviewer</h3><p>Recognize {{ currentReviewer.name }} after reviewing their submitted assessment. This rating can be sent once.</p></div><span>Required</span></div>' +
                                '<div class="s9-publisher-rating__criteria">' +
                                    '<div v-for="(rating, ratingIndex) in publisherRatings" :key="\'publisher-rating-\' + ratingIndex" class="s9-publisher-rating__row">' +
                                        '<span>{{ ratingCriteria(ratingIndex) }}</span>' +
                                        '<div class="s9-star-input" role="radiogroup" :aria-label="ratingCriteria(ratingIndex)">' +
                                            '<button v-for="star in 5" :key="star" type="button" :class="{\'is-filled\': star <= rating}" :aria-label="star + \' out of 5\'" :aria-checked="star === rating ? \'true\' : \'false\'" role="radio" @click="setPublisherRating(ratingIndex, star)">&#9733;</button>' +
                                            '<output>{{ rating ? rating + \'/5\' : \'Not rated\' }}</output>' +
                                        '</div>' +
                                    '</div>' +
                                '</div>' +
                                '<label class="s9-field"><span>Feedback <small>(optional)</small></span><input type="text" v-model="publisherRatingComment" maxlength="2000" placeholder="Add private feedback for the reviewer" autocomplete="off"></label>' +
                                '<footer><small>Ratings are sent securely to Scholar9 and cannot be edited after submission.</small><button type="button" class="s9-button s9-button--primary" :disabled="!canSubmitPublisherRating" @click="submitPublisherRating">{{ operation === \'rate_reviewer\' ? \'Sending rating…\' : \'Submit reviewer rating\' }}</button></footer>' +
                            '</section>' +
                            '<div v-else-if="isCurrentConversationClosed && publisherRatingSubmitted" class="s9-rating-sent" role="status"><span aria-hidden="true">&#10003;</span><div><strong>Reviewer rating sent</strong><p>Your publisher assessment is recorded in Scholar9.</p></div></div>' +
                            '<form v-if="!isCurrentConversationClosed" class="s9-composer" @submit.prevent="sendMessage">' +
                                '<label><span class="sr-only">Message to reviewer</span><textarea v-model="messageText" :placeholder="currentReviewer ? \'Send a message to \' + currentReviewer.name : \'Select a reviewer\'" :maxlength="maxMessageLength" :disabled="!currentReviewer || !!operation || rateLimited"></textarea></label>' +
                                '<button type="button" class="s9-button" :disabled="!currentReviewer || !files.length || !!operation || rateLimited" @click="openFileDialog">Send File</button>' +
                                '<button type="submit" class="s9-button s9-button--primary" :disabled="!currentReviewer || !messageText.trim() || !!operation || rateLimited">{{ rateLimited ? \'Rate limited (\' + rateLimitCountdown + \'s)\' : (operation === \'send_message\' ? \'Sending…\' : \'Send Message\') }}</button>' +
                            '</form>' +
                        '</main>' +
                    '</div></div>' +
                '</template>' +
                '<dialog ref="fileDialog" class="s9-file-dialog" @cancel.prevent="closeFileDialog">' +
                    '<form method="dialog" @submit.prevent="sendSelectedFile">' +
                        '<header><div><h2>Send a file</h2><p v-if="currentReviewer">Choose a submission file to send to {{ currentReviewer.name }}.</p></div><button type="button" @click="closeFileDialog" aria-label="Close">×</button></header>' +
                        '<div class="s9-dialog-files">' +
                            '<label v-for="file in files" :key="\'send-\' + file.id" :class="{\'is-selected\': String(file.id) === String(selectedFileId)}"><input type="radio" :name="namespace + \'-send-file\'" :value="String(file.id)" v-model="selectedFileId"><span class="s9-file-icon" aria-hidden="true">DOC</span><span><strong>{{ file.name }}</strong><small>{{ file.mimeType }}<template v-if="file.size"> · {{ formatBytes(file.size) }}</template></small></span></label>' +
                        '</div>' +
                        '<footer><button type="button" class="s9-button" @click="closeFileDialog">Cancel</button><button type="submit" class="s9-button s9-button--primary" :disabled="!selectedFileId || operation === \'send_file\'">{{ operation === \'send_file\' ? \'Sending…\' : \'Send File\' }}</button></footer>' +
                    '</form>' +
                '</dialog>' +
            '</section>'
    };

    registry.registerComponent(WORKSPACE_COMPONENT, Scholar9WorkflowWorkspace);
    registry.registerComponent(SHORTCUT_COMPONENT, Scholar9WorkflowShortcut);

    registry.storeExtend('workflow', function (piniaContext) {
        var store = piniaContext.store;

        function isEditorialDashboard(args) {
            var dashboardPage = args && args.dashboardPage
                ? args.dashboardPage
                : store.dashboardPage;
            return dashboardPage === 'editorialDashboard';
        }

        function isScholar9(args) {
            return !!(
                args
                && args.selectedMenuState
                && args.selectedMenuState.primaryMenuItem === STATE_KEY
            );
        }

        store.extender.extendFn('getMenuItems', function (groups, args) {
            if (!isEditorialDashboard(args)) {
                return groups;
            }
            return arrayValue(groups).map(function (group) {
                if (group.key !== 'workflow') {
                    return group;
                }
                var items = arrayValue(group.items).filter(function (item) {
                    return item.key !== MENU_KEY;
                });
                var reviewKey = 'workflow_' + window.pkp.const.WORKFLOW_STAGE_ID_EXTERNAL_REVIEW;
                var position = items.findIndex(function (item) {
                    return item.key === reviewKey;
                });
                if (position < 0) {
                    position = Math.min(1, items.length);
                }
                items.splice(position, 0, {
                    key: MENU_KEY,
                    label: 'Review with Scholar9',
                    state: {
                        primaryMenuItem: STATE_KEY,
                        title: 'Workflow: Review with Scholar9'
                    }
                });
                return Object.assign({}, group, {items: items});
            });
        });

        store.extender.extendFn('getPrimaryItems', function (items, args) {
            if (!isScholar9(args)) {
                return items;
            }
            return [{
                component: WORKSPACE_COMPONENT,
                props: {
                    submissionId: Number(args.submission.id),
                    reviewRoundId: 0,
                    namespace: 'scholar9-' + Number(args.submission.id)
                }
            }];
        });

        store.extender.extendFn('getHeaderItems', function (items, args) {
            return isScholar9(args) ? [] : items;
        });

        store.extender.extendFn('getSecondaryItems', function (items, args) {
            return isScholar9(args) ? [] : items;
        });

        store.extender.extendFn('getActionItems', function (items, args) {
            if (isScholar9(args)) {
                return [];
            }
            var state = args && args.selectedMenuState;
            var isSubmissionStage = state
                && state.primaryMenuItem === 'workflow'
                && Number(state.stageId) === Number(window.pkp.const.WORKFLOW_STAGE_ID_SUBMISSION);
            if (!isSubmissionStage || !isEditorialDashboard(args)) {
                return items;
            }
            var result = arrayValue(items).filter(function (item) {
                return item.component !== SHORTCUT_COMPONENT;
            });
            result.push({
                component: SHORTCUT_COMPONENT,
                props: {
                    submissionId: Number(args.submission.id),
                    reviewRoundId: 0,
                    onOpen: function () { store.navigateToMenu(MENU_KEY); }
                }
            });
            return result;
        });

        ['getPrimaryControlsLeft', 'getPrimaryControlsRight'].forEach(function (fnName) {
            store.extender.extendFn(fnName, function (items, args) {
                return isScholar9(args) ? [] : items;
            });
        });
    });
}());
