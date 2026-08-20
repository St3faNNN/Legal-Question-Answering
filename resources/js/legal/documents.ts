// Metadata for the legal documents. Kept in one place so the pages, the footer,
// and the registration acceptance flow all refer to the same versions.
//
// NOTE: these are DRAFTS prepared for review by a Macedonian lawyer. The version
// is "1.0-draft" and the effective date is a placeholder until the review is
// done and the documents are published.

export type LegalDocId = 'privacy' | 'terms' | 'disclaimer'

export interface LegalDocMeta {
    id: LegalDocId
    route: string
    routeName: string
    version: string
    effectiveDate: string
    titleMk: string
    titleEn: string
}

export const LEGAL_DOCUMENTS: Record<LegalDocId, LegalDocMeta> = {
    privacy: {
        id: 'privacy',
        route: '/privacy',
        routeName: 'privacy',
        version: '1.0-draft',
        effectiveDate: '[СЕ ДОПОЛНУВА · TO BE COMPLETED]',
        titleMk: 'Политика за приватност',
        titleEn: 'Privacy Policy',
    },
    terms: {
        id: 'terms',
        route: '/terms',
        routeName: 'terms',
        version: '1.0-draft',
        effectiveDate: '[СЕ ДОПОЛНУВА · TO BE COMPLETED]',
        titleMk: 'Услови за користење',
        titleEn: 'Terms of Use',
    },
    disclaimer: {
        id: 'disclaimer',
        route: '/disclaimer',
        routeName: 'disclaimer',
        version: '1.0-draft',
        effectiveDate: '[СЕ ДОПОЛНУВА · TO BE COMPLETED]',
        titleMk: 'Одрекување од одговорност',
        titleEn: 'Disclaimer',
    },
}

export const legalDocList: LegalDocMeta[] = [
    LEGAL_DOCUMENTS.privacy,
    LEGAL_DOCUMENTS.terms,
    LEGAL_DOCUMENTS.disclaimer,
]
