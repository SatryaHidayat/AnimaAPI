<?php

namespace App\GraphQL\Mutations;

use App\Jobs\ProcessOrderQueue;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class OrderMutation
{
    public function create($_, array $args): array
    {
        $input = $args['input'];

        $validator = Validator::make($input, [
            'user_id' => 'required|integer',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->failed('Validation error: ' . $validator->errors()->first());
        }

        $userResponse = Http::get(env('USER_SERVICE_URL') . '/api/users/' . $input['user_id']);

        if ($userResponse->failed() || empty($userResponse->json('data'))) {
            return $this->failed('User tidak ditemukan');
        }

        $items = [];
        $totalPrice = 0;

        foreach ($input['items'] as $item) {
            $productResponse = Http::get(env('PRODUCT_SERVICE_URL') . '/api/products/' . $item['product_id']);

            $product = $productResponse->json('data');

            if ($productResponse->failed() || empty($product)) {
                return $this->failed('Produk dengan ID ' . $item['product_id'] . ' tidak ditemukan');
            }

            if ($product['stock'] < $item['quantity']) {
                return $this->failed('Stok produk ' . $product['name'] . ' tidak mencukupi');
            }

            $subtotal = $product['price'] * $item['quantity'];
            $totalPrice += $subtotal;

            $items[] = [
                'product_id' => $product['id'],
                'product_name' => $product['name'],
                'price' => $product['price'],
                'quantity' => $item['quantity'],
                'subtotal' => $subtotal,
            ];
        }

        DB::beginTransaction();

        try {
            $order = Order::create([
                'user_id' => $input['user_id'],
                'status' => 'pending',
                'total_price' => $totalPrice,
                'notes' => $input['notes'] ?? null,
            ]);

            foreach ($items as $item) {
                $order->items()->create($item);
            }

            DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();

            return $this->failed('Gagal membuat order: ' . $exception->getMessage());
        }

        foreach ($items as $item) {
            $decreaseResponse = Http::put(
                env('PRODUCT_SERVICE_URL') . '/api/products/' . $item['product_id'] . '/decrease-stock',
                ['quantity' => $item['quantity']]
            );

            if ($decreaseResponse->failed()) {
                Log::warning('Gagal decrease stock untuk product_id: ' . $item['product_id']);
            }
        }

        ProcessOrderQueue::dispatch([
            'order_id' => $order->id,
            'customer_id' => $order->user_id,
            'items' => $items,
        ])->onQueue('order-processing');

        return $this->success('Order diterima dan sedang diproses', $order->load('items'));
    }

    public function updateStatus($_, array $args): array
    {
        $validator = Validator::make($args, [
            'status' => 'required|in:pending,paid,confirmed,cancelled',
        ]);

        if ($validator->fails()) {
            return $this->failed('Validation error: ' . $validator->errors()->first());
        }

        $order = Order::find($args['id']);

        if (! $order) {
            return $this->failed('Order not found');
        }

        $order->update(['status' => $args['status']]);

        return $this->success('Status order berhasil diupdate', $order->load('items'));
    }

    public function updateQuantity($_, array $args): array
    {
        $validator = Validator::make($args, [
            'quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return $this->failed('Validation error: ' . $validator->errors()->first());
        }

        $orderId = $args['orderId'] ?? $args['order_id'] ?? null;
        $itemId = $args['itemId'] ?? $args['item_id'] ?? null;

        $order = Order::find($orderId);

        if (! $order) {
            return $this->failed('Order not found');
        }

        if ($order->status !== 'pending') {
            return $this->failed('Order tidak dapat diubah karena status bukan pending');
        }

        $item = OrderItem::where('id', $itemId)
            ->where('order_id', $orderId)
            ->first();

        if (! $item) {
            return $this->failed('Item tidak ditemukan');
        }

        $productResponse = Http::get(env('PRODUCT_SERVICE_URL') . '/api/products/' . $item->product_id);

        $product = $productResponse->json('data');

        if ($productResponse->failed() || empty($product)) {
            return $this->failed('Produk tidak ditemukan');
        }

        $newQuantity = $args['quantity'];
        $difference = $newQuantity - $item->quantity;

        if ($difference > 0 && $product['stock'] < $difference) {
            return $this->failed('Stok produk tidak mencukupi');
        }

        DB::beginTransaction();

        try {
            if ($difference > 0) {
                Http::put(env('PRODUCT_SERVICE_URL') . '/api/products/' . $item->product_id . '/decrease-stock', [
                    'quantity' => $difference
                ]);
            } elseif ($difference < 0) {
                Http::put(env('PRODUCT_SERVICE_URL') . '/api/products/' . $item->product_id . '/increase-stock', [
                    'quantity' => abs($difference)
                ]);
            }

            $item->update([
                'quantity' => $newQuantity,
                'subtotal' => $item->price * $newQuantity,
            ]);

            $order->update([
                'total_price' => $order->items()->sum('subtotal'),
            ]);

            DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();

            return $this->failed('Gagal update quantity: ' . $exception->getMessage());
        }

        return $this->success('Quantity berhasil diupdate', $order->load('items'));
    }

    public function delete($_, array $args): array
    {
        $order = Order::with('items')->find($args['id']);

        if (! $order) {
            return $this->failed('Order not found');
        }

        $order->items()->delete();
        $order->delete();

        return $this->success('Order berhasil dihapus');
    }

    private function success(string $message, ?Order $order = null): array
    {
        return [
            'status' => 'Success',
            'message' => $message,
            'data' => $order,
        ];
    }

    private function failed(string $message): array
    {
        return [
            'status' => 'Failed',
            'message' => $message,
            'data' => null,
        ];
    }
}
