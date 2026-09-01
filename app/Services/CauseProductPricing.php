<?php

namespace App\Services;

use App\Models\CauseProduct;

class CauseProductPricing
{
    /**
     * @param CauseProduct $product
     * @param float|int $qty
     * @return array|null
     */
    public function quote(CauseProduct $product, float|int $qty): ?array
    {
        if (is_null($product->unit_price)) {
            return null;
        }

        $goods = $qty * $product->unit_price;

        $pct = $product->platform_fee_pct ?? config('qrdobem.donation.platform_fee_percent', 0);
        $platformFee = $goods * ($pct / 100);

        $shipping = $product->shipping_cost ?? 0;
        $other = $product->other_costs ?? 0;

        $extras = 0;
        $formulaKeys = is_array($product->formula_keys) ? $product->formula_keys : [];
        
        if (!empty($formulaKeys)) {
            $product->loadMissing('attributes');
            
            foreach ($product->attributes as $attr) {
                if ($attr->significance === 'financeiro' && in_array($attr->attr_key, $formulaKeys)) {
                    $extras += (float) $attr->attr_value;
                }
            }
        }

        $total = $goods + $platformFee + $shipping + $other + $extras;

        return [
            'qty' => (float) $qty,
            'unit_price' => (float) $product->unit_price,
            'goods' => round($goods, 2),
            'platform_fee_pct' => (float) $pct,
            'platform_fee' => round($platformFee, 2),
            'shipping' => round($shipping, 2),
            'other' => round($other, 2),
            'extras' => round($extras, 2),
            'total' => round($total, 2),
        ];
    }
}
