<?php

return [
    'unit_price' => env('CREDITS_UNIT_PRICE', 5.00),
    'min_quantity' => env('CREDITS_MIN_QTY', 3),
    'max_quantity' => env('CREDITS_MAX_QTY', 120),
    
    'adventure_yearly_price' => env('ADVENTURE_YEARLY_PRICE', 149.90),
    
    'family_pack_qty' => env('FAMILY_PACK_QTY', 5),
    'family_pack_price' => env('FAMILY_PACK_PRICE', 15.00),
    
    'launch_offer_enabled' => env('LAUNCH_OFFER_ENABLED', false),
    'launch_offer_discount_percent' => env('LAUNCH_OFFER_DISCOUNT_PERCENT', 0),
    'launch_offer_ends_at' => env('LAUNCH_OFFER_ENDS_AT', null),
];
