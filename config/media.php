<?php

declare(strict_types=1);

return [
    'product' => [
        'visibility' => 'public',
        'mimes'      => ['image/jpeg', 'image/png', 'image/webp'],
        'max_mb'     => 10,
        'variants'   => ['thumb' => 320, 'medium' => 800, 'large' => 1600],
        'permission' => 'media.upload',
    ],

    'dealer_image' => [
        'visibility' => 'private',
        'mimes'      => ['image/jpeg', 'image/png', 'image/webp'],
        'max_mb'     => 10,
        'variants'   => ['thumb' => 320, 'medium' => 800, 'large' => 1600],
        'permission' => 'media.upload',
    ],

    'document' => [
        'visibility' => 'private',
        'mimes'      => ['application/pdf'],
        'max_mb'     => 20,
        'permission' => 'media.upload',
    ],

    'avatar' => [
        'visibility' => 'private',
        'mimes'      => ['image/jpeg', 'image/png', 'image/webp'],
        'max_mb'     => 5,
        'variants'   => ['small' => 64, 'medium' => 256],
        'uploadable' => false,
    ],
];
