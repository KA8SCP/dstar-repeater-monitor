<?php
declare(strict_types=1);

const CACHE_TTL = 15;          // seconds; prevents excessive polling
const HTTP_TIMEOUT = 10;      // seconds
const REFRESH_SECONDS = 15;   // browser live refresh
const HISTORY_RETENTION_DAYS = 90; // SQLite observation/event retention
const HISTORY_SAMPLE_SECONDS = 60; // at most one network history sample per minute
const MAX_LAST_HEARD = 25;
const MAX_USERS = 50;
const MAX_PEERS = 25;

$REFLECTORS = [
    'WB1GOF' => [
        'name' => 'WB1GOF',
        'type' => 'DPLUS_GATEWAY',
        'host' => 'wb1gof.dstargateway.org',
        'urls' => [
            'https://wb1gof.dstargateway.org/',
            'http://wb1gof.dstargateway.org/',
        ],
    ],

    'K1HRO' => [
        'name' => 'K1HRO',
        'type' => 'DPLUS_GATEWAY',
        'host' => 'k1hro.dstargateway.org',
        'urls' => [
            'https://k1hro.dstargateway.org/',
            'http://k1hro.dstargateway.org/',
        ],
    ],

    'W1MRA' => [
        'name' => 'W1MRA',
        'type' => 'DPLUS_GATEWAY',
        'host' => 'w1mra.dstargateway.org',
        'urls' => [
            'https://w1mra.dstargateway.org/',
            'http://w1mra.dstargateway.org/',
        ],
    ],

    'K1MRA' => [
        'name' => 'K1MRA',
        'type' => 'DPLUS_GATEWAY',

        // K1MRA currently serves an incomplete HTTPS certificate chain.
        // Limit disabled certificate verification to this gateway only.
        'insecure_ssl' => true,

        'host' => 'k1mra.dstargateway.org',
        'urls' => [
            'https://k1mra.dstargateway.org/',
            'http://k1mra.dstargateway.org/',
        ],
    ],

    'KA1EAR' => [
        'name' => 'KA1EAR',
        'type' => 'DPLUS_GATEWAY',
        'host' => 'ka1ear.dstargateway.org',
        'urls' => [
            'https://ka1ear.dstargateway.org/',
            'http://ka1ear.dstargateway.org/',
        ],
    ],

    'W1SCV' => [
        'name' => 'W1SCV',
        'type' => 'DPLUS_GATEWAY',
        'host' => 'w1scv.dstargateway.org',
        'urls' => [
            'https://w1scv.dstargateway.org/',
            'http://w1scv.dstargateway.org/',
        ],
    ],

    'KS1R' => [
        'name' => 'KS1R',
        'type' => 'DPLUS_GATEWAY',
        'host' => 'ks1r.dstargateway.org',
        'urls' => [
            'https://ks1r.dstargateway.org/',
            'http://ks1r.dstargateway.org/',
        ],
    ],

    'KD8QOF' => [
        'name' => 'KD8QOF',
        'type' => 'DPLUS_GATEWAY',
        'host' => 'kd8qof.dstargateway.org',
        'urls' => [
            'https://kd8qof.dstargateway.org/',
            'http://kd8qof.dstargateway.org/',
        ],
    ],
];
