<?php

namespace App\Http\Controllers;

use App\Models\CauseProduct;
use App\Models\Space;
use App\Policies\SpacePolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CauseProductController extends Controller
{
    /**
     * GET /spaces/{space}/products
     */
    public function index(Request $request, $spaceId)
    {
        $space = Space::find($spaceId);

        if (!$space || $space->type !== Space::TYPE_CAUSE) {
            return response()->json(['error' => 'Espaço de causa não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'space.edit');

        $products = CauseProduct::with(['attributes', 'substitutes'])
            ->where('space_id', $space->id)
            ->get();

        return response()->json([
            'products' => $products
        ]);
    }

    /**
     * POST /spaces/{space}/products
     */
    public function store(Request $request, $spaceId)
    {
        $space = Space::find($spaceId);

        if (!$space || $space->type !== Space::TYPE_CAUSE) {
            return response()->json(['error' => 'Espaço de causa não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'space.edit');

        $validated = $this->validateProduct($request, $space->id);

        $product = DB::transaction(function () use ($validated, $space, $request) {
            $product = CauseProduct::create(array_merge($validated, ['space_id' => $space->id]));

            if ($request->has('attributes')) {
                $this->syncAttributes($product, $request->input('attributes', []));
            }

            if ($request->has('substitutes')) {
                $this->syncSubstitutes($product, $request->input('substitutes', []), $space->id);
            }

            return $product->load(['attributes', 'substitutes']);
        });

        return response()->json([
            'message' => 'Produto criado com sucesso.',
            'product' => $product,
        ], 201);
    }

    /**
     * PUT /spaces/{space}/products/{product}
     */
    public function update(Request $request, $spaceId, $productId)
    {
        $space = Space::find($spaceId);

        if (!$space || $space->type !== Space::TYPE_CAUSE) {
            return response()->json(['error' => 'Espaço de causa não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'space.edit');

        $product = CauseProduct::where('space_id', $space->id)->find($productId);

        if (!$product) {
            return response()->json(['error' => 'Produto não encontrado.'], 404);
        }

        $validated = $this->validateProduct($request, $space->id, $product->id);

        $product = DB::transaction(function () use ($validated, $product, $request, $space) {
            $product->update($validated);

            if ($request->has('attributes')) {
                $this->syncAttributes($product, $request->input('attributes', []));
            }

            if ($request->has('substitutes')) {
                $this->syncSubstitutes($product, $request->input('substitutes', []), $space->id);
            }

            return $product->fresh(['attributes', 'substitutes']);
        });

        return response()->json([
            'message' => 'Produto atualizado com sucesso.',
            'product' => $product,
        ]);
    }

    /**
     * DELETE /spaces/{space}/products/{product}
     */
    public function destroy(Request $request, $spaceId, $productId)
    {
        $space = Space::find($spaceId);

        if (!$space || $space->type !== Space::TYPE_CAUSE) {
            return response()->json(['error' => 'Espaço de causa não encontrado.'], 404);
        }

        app(SpacePolicy::class)->authorize($request->tenant, $space, 'space.edit');

        $product = CauseProduct::where('space_id', $space->id)->find($productId);

        if (!$product) {
            return response()->json(['error' => 'Produto não encontrado.'], 404);
        }

        $product->delete();

        return response()->json([
            'message' => 'Produto removido com sucesso.',
        ]);
    }

    private function validateProduct(Request $request, $spaceId, $ignoreProductId = null)
    {
        // Explicarmente recusando donation_value.
        if ($request->has('donation_value')) {
            abort(422, 'O campo donation_value não é permitido.');
        }

        return $request->validate([
            'name'             => 'required|string|max:255',
            'purpose'          => 'required|string|max:255',
            'unit'             => 'required|string|max:255',
            'unit_price'       => 'required|numeric|min:0',
            'platform_fee_pct' => 'nullable|numeric|min:0|max:100',
            'shipping_cost'    => 'nullable|numeric|min:0',
            'other_costs'      => 'nullable|numeric|min:0',
            'barcode'          => 'nullable|string|max:255',
            'manufacturer'     => 'nullable|string|max:255',
            'distributor'      => 'nullable|string|max:255',
            'formula_keys'     => 'nullable|array',
            
            // Arrays (serão tratados via sync se existirem)
            'attributes'                => 'nullable|array',
            'attributes.*.attr_key'     => 'required_with:attributes|string|max:255',
            'attributes.*.attr_value'   => 'required_with:attributes|string|max:255',
            'attributes.*.significance' => ['required_with:attributes', Rule::in(['financeiro', 'identidade', 'apresentacao', 'comercial', 'logistica', 'uso'])],
            
            'substitutes'                  => 'nullable|array',
            'substitutes.*.substitute_id'  => [
                'required_with:substitutes',
                'integer',
                // Substitute deve pertencer ao mesmo space e não pode ser o mesmo produto
                Rule::exists('cause_products', 'id')->where(function ($query) use ($spaceId) {
                    $query->where('space_id', $spaceId);
                }),
                function ($attribute, $value, $fail) use ($ignoreProductId) {
                    if ($ignoreProductId && $value == $ignoreProductId) {
                        $fail('Um produto não pode ser substituto de si mesmo.');
                    }
                },
            ],
            'substitutes.*.sort_order'     => 'nullable|integer',
            'substitutes.*.reason'         => ['required_with:substitutes', Rule::in(['falta', 'preco', 'finalidade'])],
            'substitutes.*.qty_equivalent' => 'nullable|numeric|min:0',
        ]);
    }

    private function syncAttributes(CauseProduct $product, array $attributes)
    {
        $product->attributes()->delete();
        
        $inserts = [];
        foreach ($attributes as $attr) {
            $inserts[] = [
                'attr_key'     => $attr['attr_key'],
                'attr_value'   => $attr['attr_value'],
                'significance' => $attr['significance'],
            ];
        }
        
        if (!empty($inserts)) {
            $product->attributes()->createMany($inserts);
        }
    }

    private function syncSubstitutes(CauseProduct $product, array $substitutes, $spaceId)
    {
        $product->substitutes()->delete();
        
        $inserts = [];
        foreach ($substitutes as $sub) {
            $inserts[] = [
                'substitute_id'  => $sub['substitute_id'],
                'sort_order'     => $sub['sort_order'] ?? 0,
                'reason'         => $sub['reason'],
                'qty_equivalent' => $sub['qty_equivalent'] ?? null,
            ];
        }

        if (!empty($inserts)) {
            $product->substitutes()->createMany($inserts);
        }
    }
}
