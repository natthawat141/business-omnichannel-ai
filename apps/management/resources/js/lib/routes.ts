/**
 * Central URL builder for the admin surface. We deliberately avoid Ziggy to keep
 * the toolchain small; these mirror the names in routes/web.php.
 */

const admin = (path: string) => `/admin${path}`;

export const routes = {
    login: '/login',
    loginFirebase: '/login/firebase',
    logout: '/logout',
    pendingApproval: '/pending-approval',
    pendingApprovalCheck: '/pending-approval/check',
    dashboard: admin('/dashboard'),
    guide: admin('/guide'),
    users: {
        index: admin('/users'),
        create: admin('/users/create'),
        store: admin('/users'),
        edit: (id: number) => admin(`/users/${id}/edit`),
        update: (id: number) => admin(`/users/${id}`),
        approve: (id: number) => admin(`/users/${id}/approve`),
        reject: (id: number) => admin(`/users/${id}/reject`),
        passwordLink: (id: number) => admin(`/users/${id}/password-link`),
    },

    categories: {
        index: admin('/package-categories'),
        create: admin('/package-categories/create'),
        store: admin('/package-categories'),
        edit: (id: number) => admin(`/package-categories/${id}/edit`),
        update: (id: number) => admin(`/package-categories/${id}`),
        destroy: (id: number) => admin(`/package-categories/${id}`),
    },
    packages: {
        index: admin('/packages'),
        create: admin('/packages/create'),
        store: admin('/packages'),
        edit: (id: number) => admin(`/packages/${id}/edit`),
        update: (id: number) => admin(`/packages/${id}`),
        destroy: (id: number) => admin(`/packages/${id}`),
    },
    propertyImages: {
        directUpload: admin('/property-images/direct-upload'),
    },
    faqs: {
        index: admin('/faqs'),
        create: admin('/faqs/create'),
        store: admin('/faqs'),
        edit: (id: number) => admin(`/faqs/${id}/edit`),
        update: (id: number) => admin(`/faqs/${id}`),
        destroy: (id: number) => admin(`/faqs/${id}`),
    },
    knowledge: {
        index: admin('/knowledge'),
        create: admin('/knowledge/create'),
        store: admin('/knowledge'),
        edit: (id: number) => admin(`/knowledge/${id}/edit`),
        update: (id: number) => admin(`/knowledge/${id}`),
        destroy: (id: number) => admin(`/knowledge/${id}`),
    },
    businessProfile: {
        edit: admin('/business-profile'),
        update: admin('/business-profile'),
    },
    apiTokens: {
        index: admin('/api-tokens'),
        store: admin('/api-tokens'),
        destroy: (id: number) => admin(`/api-tokens/${id}`),
    },
    imports: {
        index: admin('/imports'),
        preview: admin('/imports/packages/preview'),
        confirm: admin('/imports/packages/confirm'),
        cancel: admin('/imports/packages/cancel'),
        template: admin('/imports/packages/template'),
        exportUrl: admin('/exports/packages'),
    },
    agentChanges: {
        index: admin('/agent-changes'),
        show: (id: string) => admin(`/agent-changes/${id}`),
        approve: (id: string) => admin(`/agent-changes/${id}/approve`),
        apply: (id: string) => admin(`/agent-changes/${id}/apply`),
        bulkApply: admin('/agent-changes/bulk-apply'),
        reject: (id: string) => admin(`/agent-changes/${id}/reject`),
    },
    publicHome: '/',
};
