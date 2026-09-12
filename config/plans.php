<?php

return [

    'limits' => [
        'studio' => ['max_galleries' => 999, 'max_images' => 500],
        'pro'    => ['max_galleries' => 5,   'max_images' => 100],
        'free'   => ['max_galleries' => 1,   'max_images' => 10],
    ],

    'display' => [
        'free' => [
            'name'        => 'Free',
            'price'       => 0,
            'price_label' => 'Free',
            'tagline'     => '1 gallery · 10 images',
            'features'    => [
                '1 gallery',
                '10 images per gallery',
                'Standard venue templates',
                'Community support',
            ],
        ],
        'pro' => [
            'name'        => 'Pro',
            'price'       => 29,
            'price_label' => '$29',
            'tagline'     => '5 galleries · 100 images',
            'features'    => [
                '5 galleries',
                '100 images per gallery',
                'Background music',
                'No Exospace watermark',
                'Priority email support',
            ],
        ],
        'studio' => [
            'name'        => 'Studio',
            'price'       => 99,
            'price_label' => '$99',
            'tagline'     => 'Unlimited galleries · 500 images',
            'features'    => [
                'Unlimited galleries',
                '500 images per gallery',
                'Custom domains',
                'White-label (no Exospace branding)',
                'Custom gallery logos',
                'Priority support',
            ],
        ],
    ],

    'rank' => [
        'free'   => 0,
        'pro'    => 1,
        'studio' => 2,
    ],
];
