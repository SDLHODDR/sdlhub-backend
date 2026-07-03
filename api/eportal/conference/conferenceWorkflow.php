<?php

return [

    /* ================= WORKFLOW STATES ================= */

    'states' => [
        'N' => 'Planned',
        'T' => 'Sent For Confirmation',
        'A' => 'Confirmed',
        'X' => 'Cancelled',
        'D' => 'Deleted',
        'R' => 'Rejected'
    ],

    /* ================= TRANSITIONS ================= */

    'transitions' => [

        'edit' => [
            'from' => ['N'],
            'to'   => 'N'
        ],

        'send_confirmation' => [
            'from' => ['N'],
            'to'   => 'T'
        ],

        'delete' => [
            'from' => ['N'],
            'to'   => 'D'
        ],

        'cancel' => [
            'from' => ['T','A'],
            'to'   => 'X'
        ]

    ]
];
