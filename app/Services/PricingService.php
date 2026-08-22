<?php

namespace App\Services;

use App\Models\CreditPricing;
use Carbon\Carbon;

class PricingService
{
    /**
     * Obter as configurações combinando banco (prioridade) e arquivo config/env
     */
    public function getSettings()
    {
        $db = CreditPricing::first();
        
        $cfg = config('pricing');

        return [
            'unit_price' => $db->unit_price ?? $cfg['unit_price'],
            'min_quantity' => $db->min_quantity ?? $cfg['min_quantity'],
            'max_quantity' => $db->max_quantity ?? $cfg['max_quantity'],
            
            'adventure_yearly_price' => $db->adventure_yearly_price ?? $cfg['adventure_yearly_price'],
            
            'family_pack_qty' => $db->family_pack_qty ?? $cfg['family_pack_qty'],
            'family_pack_price' => $db->family_pack_price ?? $cfg['family_pack_price'],
            
            'launch_offer_enabled' => $db->launch_offer_enabled ?? $cfg['launch_offer_enabled'],
            'launch_offer_discount_percent' => $db->launch_offer_discount_percent ?? $cfg['launch_offer_discount_percent'],
            'launch_offer_ends_at' => $db->launch_offer_ends_at ?? $cfg['launch_offer_ends_at'],
        ];
    }

    public function isLaunchOfferActive(array $settings)
    {
        if (!$settings['launch_offer_enabled']) {
            return false;
        }

        if (empty($settings['launch_offer_ends_at'])) {
            return true;
        }

        return Carbon::parse($settings['launch_offer_ends_at'])->endOfDay()->isPast() === false;
    }

    public function effectivePrice($listPrice, array $settings)
    {
        if ($this->isLaunchOfferActive($settings) && $settings['launch_offer_discount_percent'] > 0) {
            $discount = (float) $settings['launch_offer_discount_percent'];
            return round((float)$listPrice * (1 - ($discount / 100)), 2);
        }
        return (float) $listPrice;
    }

    public function getPricingPayload()
    {
        $settings = $this->getSettings();
        $isActive = $this->isLaunchOfferActive($settings);

        return [
            // Contrato antigo mantido
            'unit_price' => (float) $settings['unit_price'],
            'min_quantity' => (int) $settings['min_quantity'],
            'max_quantity' => (int) $settings['max_quantity'],
            'currency' => 'BRL',
            
            // Novos campos aditivos
            'unit_price_effective' => $this->effectivePrice($settings['unit_price'], $settings),
            
            'adventure_yearly_price' => (float) $settings['adventure_yearly_price'],
            'adventure_yearly_price_effective' => $this->effectivePrice($settings['adventure_yearly_price'], $settings),
            
            'family_pack_qty' => (int) $settings['family_pack_qty'],
            'family_pack_price' => (float) $settings['family_pack_price'],
            'family_pack_price_effective' => $this->effectivePrice($settings['family_pack_price'], $settings),
            
            'launch_offer' => [
                'enabled' => (bool) $settings['launch_offer_enabled'],
                'discount_percent' => (float) $settings['launch_offer_discount_percent'],
                'ends_at' => $settings['launch_offer_ends_at'] ? Carbon::parse($settings['launch_offer_ends_at'])->format('Y-m-d') : null,
                'active' => $isActive,
            ],
        ];
    }
}
