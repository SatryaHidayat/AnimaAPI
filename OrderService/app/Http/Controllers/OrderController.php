<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrderResource;
use Illuminate\Support\Facades\Log;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use App\Jobs\ProcessOrderQueue;


class OrderController extends Controller
{
    // GET /api/orders
    public function index()
    {
        $orders = Order::with('items')->latest()->get();
        $orders->each(function ($order) {
            $order->makeHidden(['deleted_at']);
            $order->items->each->makeHidden(['deleted_at']);
        });
        return new OrderResource('Success', 'List of orders', $orders);
    }

    // GET /api/orders/{id}
    public function show($id)
    {
        $order = Order::with('items')->find($id);

        if (!$order) {
            return new OrderResource('Failed', 'Order not found', null);
        }

        $data = $order->toArray();

        // Consume UserService — enrich data user
        $userResponse = Http::get(env('USER_SERVICE_URL') . '/api/users/' . $order->user_id);
        $data['user'] = $userResponse->successful() ? $userResponse->json()['data'] : null;

        // Consume ProductService — enrich data tiap item
        foreach ($data['items'] as $index => $item) {
            $productResponse = Http::get(env('PRODUCT_SERVICE_URL') . '/api/products/' . $item['product_id']);
            $data['items'][$index]['product'] = $productResponse->successful()
                ? $productResponse->json()['data']
                : null;
        }

        return new OrderResource('Success', 'Order found', $data);
    }

    // GET /api/orders/user/filter?user_id=
    public function getByUser(Request $request)
    {
        $userId = $request->query('user_id');

        if (!$userId) {
            return new OrderResource('Failed', 'Parameter user_id diperlukan', null);
        }

        $orders = Order::with('items')
            ->where('user_id', $userId)
            ->latest()
            ->get();

        return new OrderResource('Success', 'List of orders by user', $orders);
    }

    // POST /api/orders
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id'            => 'required|integer',
            'items'              => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity'   => 'required|integer|min:1',
            'notes'              => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return new OrderResource('Failed', 'Validation error', $validator->errors());
        }

        // Consume UserService — validasi user ada
        $userResponse = Http::get(env('USER_SERVICE_URL') . '/api/users/' . $request->user_id);

        if ($userResponse->failed()) {
            return new OrderResource('Failed', 'User tidak ditemukan', null);
        }

        $items      = [];
        $totalPrice = 0;

        foreach ($request->items as $item) {
            $productResponse = Http::get(env('PRODUCT_SERVICE_URL') . '/api/products/' . $item['product_id']);

            if ($productResponse->failed()) {
                return new OrderResource('Failed', 'Produk dengan ID ' . $item['product_id'] . ' tidak ditemukan', null);
            }

            $product = $productResponse->json('data');

            if ($product['stock'] < $item['quantity']) {
                return new OrderResource('Failed', 'Stok produk ' . $product['name'] . ' tidak mencukupi', null);
            }

            $subtotal    = $product['price'] * $item['quantity'];
            $totalPrice += $subtotal;

            $items[] = [
                'product_id'   => $product['id'],
                'product_name' => $product['name'],
                'price'        => $product['price'],
                'quantity'     => $item['quantity'],
                'subtotal'     => $subtotal,
            ];
        }

        DB::beginTransaction();
        try {
            $order = Order::create([
                'user_id'     => $request->user_id,
                'status'      => 'pending',
                'total_price' => $totalPrice,
                'notes'       => $request->notes,
            ]);

            foreach ($items as $item) {
                $order->items()->create($item);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return new OrderResource('Failed', 'Gagal membuat order: ' . $e->getMessage(), null);
        }

        // Decrease stock setelah commit
        foreach ($items as $item) {
            $decreaseResponse = Http::put(
                env('PRODUCT_SERVICE_URL') . '/api/products/' . $item['product_id'] . '/decrease-stock',
                ['quantity' => $item['quantity']]
            );

            if ($decreaseResponse->failed()) {
                // Log warning — order sudah dibuat, tandai untuk manual review
                Log::warning('Gagal decrease stock untuk product_id: ' . $item['product_id']);
            }
        }

        // ✅ Dispatch SEBELUM return, dengan data yang benar
        $orderData = [
            'order_id'    => $order->id,           // ✅ pakai ID dari DB
            'customer_id' => $order->user_id,      // ✅ bukan customer_id
            'items'       => $items,               // ✅ pakai $items yang sudah diproses
        ];

        ProcessOrderQueue::dispatch($orderData)->onQueue('order-processing');

        return new OrderResource('Success', 'Order diterima dan sedang diproses', $order->load('items'));
        // DB::beginTransaction();
        // try {
        //     $order = Order::create([
        //         'user_id'     => $request->user_id,
        //         'status'      => 'pending',
        //         'total_price' => $totalPrice,
        //         'notes'       => $request->notes,
        //     ]);

        //     foreach ($items as $item) {
        //         $order->items()->create($item);
        //     }

        //     DB::commit();
        //     // ... di dalam loop foreach ($items as $item) atau setelah DB::commit()

        //     foreach ($items as $item) {
        //         $decreaseResponse = Http::put(env('PRODUCT_SERVICE_URL') . '/api/products/' . $item['product_id'] . '/decrease-stock', [
        //             'quantity' => $item['quantity']
        //         ]);

        //         if ($decreaseResponse->failed()) {
        //             // Logika penanganan jika gagal (misal: rollback order atau tandai error)
        //         }
        //     }
        // } catch (\Exception $e) {
        //     DB::rollBack();
        //     return new OrderResource('Failed', 'Gagal membuat order: ' . $e->getMessage(), null);
        // }

        // $orderData = [
        //     'order_id' => 'ORD-' . time(),
        //     'customer_id' => $request->input('customer_id'),
        //     'items' => $request->input('items'),
        // ];



        // ProcessOrderQueue::dispatch($orderData)->onQueue('order-processing');

        // return new OrderResource('Success', 'Order diterima dan sedang diproses', $order->load('items'));

        // Lemparkan data tersebut ke RabbitMQ melalui Job


        // return response()->json([
        //     'status' => 'success',
        //     'message' => 'Order diterima dan masuk ke dalam antrean pemrosesan.'
        // ]);





    }

    // PUT /api/orders/{id}/status
    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:pending,processing,paid,completed,cancelled',
        ]);

        if ($validator->fails()) {
            return new OrderResource('Failed', 'Validation error', $validator->errors());
        }

        $order = Order::find($id);

        if (!$order) {
            return new OrderResource('Failed', 'Order not found', null);
        }

        $order->update(['status' => $request->status]);

        return new OrderResource('Success', 'Status order berhasil diupdate', $order);
    }
    public function updateQuantity(Request $request, $orderId, $itemId)
    {
        $validator = Validator::make($request->all(), [
            'quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return new OrderResource('Failed', 'Validation error', $validator->errors());
        }

        // Cek order ada
        $order = Order::find($orderId);
        if (!$order) {
            return new OrderResource('Failed', 'Order not found', null);
        }

        // Cek status order masih pending
        if ($order->status !== 'pending') {
            return new OrderResource('Failed', 'Order tidak dapat diubah karena status bukan pending', null);
        }

        // Cek item ada dan milik order ini
        $item = OrderItem::where('id', $itemId)
            ->where('order_id', $orderId)
            ->first();

        if (!$item) {
            return new OrderResource('Failed', 'Item tidak ditemukan', null);
        }

        // Consume ProductService — cek stok mencukupi
        $productResponse = Http::get(env('PRODUCT_SERVICE_URL') . '/api/products/' . $item->product_id);

        if ($productResponse->failed()) {
            return new OrderResource('Failed', 'Produk tidak ditemukan', null);
        }

        $product = $productResponse->json('data');
        $newQuantity = $request->quantity;
        $oldQuantity = $item->quantity;
        $selisih = $newQuantity - $oldQuantity;

        // Cek stok mencukupi jika quantity bertambah
        if ($selisih > 0 && $product['stock'] < $selisih) {
            return new OrderResource('Failed', 'Stok produk tidak mencukupi', null);
        }

        DB::beginTransaction();
        try {
            // Hitung ulang subtotal item
            $newSubtotal = $item->price * $newQuantity;
            $item->update([
                'quantity' => $newQuantity,
                'subtotal' => $newSubtotal,
            ]);

            // Hitung ulang total_price order
            $newTotalPrice = $order->items()->sum('subtotal');
            $order->update(['total_price' => $newTotalPrice]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return new OrderResource('Failed', 'Gagal update quantity: ' . $e->getMessage(), null);
        }

        return new OrderResource('Success', 'Quantity berhasil diupdate', $order->load('items'));
    }
    public function destroy($id)
    {
        $order = Order::with('items')->find($id);

        if (!$order) {
            return new OrderResource('Failed', 'Order not found', null);
        }

        $order->items()->delete();
        $order->delete();

        return new OrderResource('Success', 'Order berhasil dihapus', null);
    }
}
