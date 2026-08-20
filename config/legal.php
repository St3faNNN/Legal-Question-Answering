<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Legal document versions
    |--------------------------------------------------------------------------
    |
    | Authoritative, server-side version of each legal document. This is the
    | source of truth for what a user actually agreed to: at registration the
    | recorded acceptance uses THESE versions, never a value supplied by the
    | client. Bump a version here when a document changes so a future change is
    | distinguishable from the version a user agreed to. Keep in sync with the
    | frontend display metadata in resources/js/legal/documents.ts.
    |
    */

    'documents' => [
        'terms' => ['version' => '1.0-draft'],
        'privacy' => ['version' => '1.0-draft'],
        'disclaimer' => ['version' => '1.0-draft'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Acceptance required at registration
    |--------------------------------------------------------------------------
    |
    | The documents a user must explicitly accept to create an account. One
    | acceptance row is recorded per document, with its version and a timestamp.
    |
    */

    'acceptance_required' => ['terms', 'privacy'],

];
