<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Exceptions\DomainException;
use App\Http\Controllers\Api\V1\Inventory\InventoryController;
use App\Models\CashRegisterSession;
use App\Models\HeldCart;
use App\Rules\TenantExists;
use App\Services\Audit\AuditLogger;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Held ("parked") carts of the selected branch. Any cashier of the branch
 * can resume one; resuming removes it. Only the cart as entered is kept —
 * it is priced again when resumed.
 */
class HeldCartController extends InventoryController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        $carts = HeldCart::query()->with(['user', 'customer', 'terminal'])
            ->where('branch_id', $this->branch()->getKey())
            ->latest()->limit(100)->get();

        return ApiResponse::data($carts->map(fn (HeldCart $c) => $this->present($c))->all());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_uuid' => ['required', 'uuid'],
            'label' => ['nullable', 'string', 'max:80'],
            'customer_id' => ['nullable', 'integer', TenantExists::on('customers')],
            'cart' => ['required', 'array'],
            'cart.items' => ['required', 'array', 'min:1', 'max:200'],
            'cart.items.*.product_variant_id' => ['required', 'integer'],
            'cart.items.*.qty' => ['required', 'numeric'],
            'cart.preview' => ['nullable', 'array'],
        ], ['cart.items.required' => 'The cart is empty.', 'cart.items.min' => 'The cart is empty.']);
        // Keep the whole cart as entered (discounts, SC/PWD, notes...); it is re-validated at checkout.
        $data['cart'] = $request->input('cart');
        // Senior/PWD ID numbers are personal data: never parked in plain text; re-entered on resume.
        unset($data['cart']['sc_pwd']['id_no']);
        if (strlen((string) json_encode($data['cart'])) > 65000) {
            throw new DomainException('This cart is too large to hold.', 'CART_TOO_LARGE', 422);
        }
        $actor = $this->actor($request);
        $branch = $this->branch();

        $session = CashRegisterSession::query()->where('user_id', $actor->getKey())->where('status', 'open')->first();
        if (! $session || $session->branch_id !== $branch->getKey()) {
            throw new DomainException('Start a shift in this branch first.', 'NO_OPEN_SHIFT', 409);
        }

        $existing = HeldCart::query()->where('client_uuid', $data['client_uuid'])->first();
        $cart = $existing ?? HeldCart::query()->create([
            'client_uuid' => $data['client_uuid'],
            'branch_id' => $branch->getKey(),
            'terminal_id' => $session->terminal_id,
            'user_id' => $actor->getKey(),
            'customer_id' => $data['customer_id'] ?? null,
            'label' => $data['label'] ?? null,
            'cart' => $data['cart'],
        ]);

        return ApiResponse::data($this->present($cart->load(['user', 'customer', 'terminal'])), $existing ? 200 : 201);
    }

    /**
     * Take the cart back to the screen: returns it and removes it from the list.
     */
    public function resume(HeldCart $heldCart): JsonResponse
    {
        $this->assertBranch($heldCart);

        return DB::transaction(function () use ($heldCart) {
            $cart = HeldCart::query()->with(['user', 'customer', 'terminal'])->lockForUpdate()->find($heldCart->getKey())
                ?? throw new DomainException('This cart was already resumed by someone else.', 'HELD_CART_GONE', 409);
            $payload = $this->present($cart) + ['cart' => $cart->cart];
            $cart->delete();

            return ApiResponse::data($payload);
        });
    }

    public function destroy(Request $request, HeldCart $heldCart): JsonResponse
    {
        $this->assertBranch($heldCart);
        $actor = $this->actor($request);
        if ($heldCart->user_id !== $actor->getKey() && ! $actor->can('pos.cancel_cart')) {
            throw new DomainException('Only the cashier who held this cart can discard it.', 'FORBIDDEN', 403);
        }

        $this->audit->log('pos.held_cart_discarded', $heldCart, meta: [
            'label' => $heldCart->label,
            'items' => count($heldCart->cart['items'] ?? []),
            'held_by' => $heldCart->user_id,
        ]);
        $heldCart->delete();

        return ApiResponse::message('Held cart discarded.');
    }

    private function assertBranch(HeldCart $cart): void
    {
        if ($cart->branch_id !== $this->branch()->getKey()) {
            throw new DomainException('Resource not found.', 'NOT_FOUND', 404);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(HeldCart $c): array
    {
        $items = $c->cart['items'] ?? [];

        return [
            'id' => $c->id,
            'label' => $c->label,
            'customer' => $c->customer ? ['id' => $c->customer->id, 'name' => $c->customer->name] : null,
            'held_by' => $c->user ? ['id' => $c->user->id, 'name' => $c->user->name] : null,
            'terminal' => $c->terminal ? ['id' => $c->terminal->id, 'name' => $c->terminal->name] : null,
            'item_count' => count($items),
            'preview' => $c->cart['preview'] ?? null,
            'held_at' => $c->created_at?->toIso8601String(),
        ];
    }
}
