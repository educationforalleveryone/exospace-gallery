import * as Sentry from '@sentry/browser';

// Browser error reporting onto the same Sentry project the backend uses.
// The DSN is injected per-page by the monitoring bootstrap partial; without
// it (local tooling, the QA harness) nothing is initialised and the helpers
// become no-ops.

// Per-page cap so a runaway error loop (e.g. an exception thrown every
// animation frame) cannot flood the ingest. The SDK's built-in dedupe and
// the server-side quota remain the primary defences.
const MAX_EVENTS_PER_PAGE = 20;

const IGNORED_ERROR_PATTERNS = [
    // Engine-internal layout notification, not an application failure.
    /^ResizeObserver loop (limit exceeded|completed with undelivered notifications\.?)/i,
    // Cross-origin scripts expose no actionable detail.
    /^Script error\.?$/i,
];

const IGNORED_URL_PATTERNS = [
    /^chrome-extension:\/\//i,
    /^moz-extension:\/\//i,
    /^safari-extension:\/\//i,
    /^webkit-masked-url:\/\//i,
];

let initialised = false;

function budgetExceeded() {
    const store = (window.__exospaceErrorBudget = window.__exospaceErrorBudget || { sent: 0 });
    return store.sent >= MAX_EVENTS_PER_PAGE;
}

function chargeBudget() {
    const store = (window.__exospaceErrorBudget = window.__exospaceErrorBudget || { sent: 0 });
    store.sent += 1;
}

// Deep links, signed asset URLs and preview override payloads must never be
// attached to a report — keep the path only.
function cleanUrl(value) {
    if (typeof value !== 'string') return value;
    return value.replace(/[?#].*$/, '');
}

function cleanText(value) {
    if (typeof value !== 'string') return value;
    return value.replace(/(https?:\/\/[^\s"'<>]*)(\?[^\s"'<>]*)/g, '$1');
}

function scrubEvent(event) {
    if (event.request) {
        event.request.url = cleanUrl(event.request.url);
        delete event.request.cookies;
        delete event.request.headers;
    }
    if (typeof event.culprit === 'string') event.culprit = cleanUrl(event.culprit);

    const values = event.exception?.values;
    if (Array.isArray(values)) {
        for (const ex of values) {
            ex.value = cleanText(ex.value);
            const frames = ex.stacktrace?.frames;
            if (Array.isArray(frames)) {
                for (const frame of frames) {
                    frame.filename = cleanUrl(frame.filename);
                    frame.abs_path = cleanUrl(frame.abs_path);
                }
            }
        }
    }

    if (Array.isArray(event.breadcrumbs)) {
        for (const crumb of event.breadcrumbs) {
            const data = crumb.data;
            if (data && typeof data === 'object') {
                for (const key of ['url', 'to', 'from']) {
                    if (typeof data[key] === 'string') data[key] = cleanUrl(data[key]);
                }
            }
        }
    }

    return event;
}

export function initMonitoring() {
    if (initialised || window.__exospaceMonitoringInit) return;
    const dsn = window.EXOSPACE_SENTRY_DSN;
    if (!dsn || typeof Sentry?.init !== 'function') return;

    initialised = true;
    window.__exospaceMonitoringInit = true;

    Sentry.init({
        dsn,
        environment: window.EXOSPACE_ENVIRONMENT || 'production',
        release: window.EXOSPACE_RELEASE || undefined,
        tracesSampleRate: 0,
        sendDefaultPii: false,
        autoSessionTracking: false,
        ignoreErrors: IGNORED_ERROR_PATTERNS,
        denyUrls: IGNORED_URL_PATTERNS,
        beforeSend(event) {
            if (budgetExceeded()) return null;
            chargeBudget();
            return scrubEvent(event);
        },
    });
}

// Explicit capture for failures that are handled locally (try/catch, loader
// rejection) and therefore never reach the global handlers.
export function reportException(error, context = {}) {
    if (!initialised) initMonitoring();
    if (!initialised || budgetExceeded() || typeof Sentry?.captureException !== 'function') return;

    Sentry.withScope((scope) => {
        for (const [key, value] of Object.entries(context)) {
            if (value === undefined || value === null) continue;
            scope.setTag(`exospace.${key}`, String(value).slice(0, 64));
        }
        Sentry.captureException(error);
    });
}
