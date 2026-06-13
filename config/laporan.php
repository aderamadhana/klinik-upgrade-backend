<?php

return [
    'insentif_apoteker' => [
        /*
         * Insentif diberikan satu kali untuk setiap resep/faktur yang telah
         * diselesaikan oleh apoteker atau asisten apoteker.
         */
        'fee_per_resep' => (float) env('INSENTIF_APOTEKER_PER_RESEP', 2000),
    ],
];
