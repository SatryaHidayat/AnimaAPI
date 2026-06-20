<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Products;
use App\Http\Resources\ProductsResource;
use Illuminate\Support\Facades\Validator;

class ControllerProducts extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $products = Products::all();
        return new ProductsResource('success', 'List of all products', $products);
    }

    public function decreaseStock(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Validation error',
                'data' => $validator->errors()
            ], 422);
        }

        $product = Products::find($id);

        if (!$product) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Product not found',
                'data' => null
            ], 404);
        }

        if ($product->stock < $request->quantity) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Stok tidak mencukupi',
                'data' => null
            ], 400);
        }

        $product->decrement('stock', $request->quantity);
        $product->save();

        return response()->json([
            'status' => 'Success',
            'message' => 'Stock berhasil dikurangi',
            'data' => $product
        ]);
    }

    public function increaseStock(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Validation error',
                'data' => $validator->errors()
            ], 422);
        }

        $product = Products::find($id);

        if (!$product) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Product not found',
                'data' => null
            ], 404);
        }

        $product->increment('stock', $request->quantity);
        $product->save();

        return response()->json([
            'status' => 'Success',
            'message' => 'Stock berhasil ditambahkan',
            'data' => $product
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric',
            'stock' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return new ProductsResource('error', 'Validation failed', $validator->errors());
        }

        $student = Products::create($request->all());
        return new ProductsResource('success', 'Product created successfully', $student);
    }

    /**
     * Display the specified resource.
     */
    public function show(int $id)
    {
        $student = Products::find($id);
        if ($student) {
            return new ProductsResource('success', 'Product found', $student);
        } else {
            return new ProductsResource('error', 'Product not found', null);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, int $id)
    {
        $products = Products::find($id);
        if ($products) {
            $products->update($request->all());
            return new ProductsResource('success', 'Product updated successfully', $products);
        } else {
            return new ProductsResource('error', 'Product not found', null);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id)
    {
        $student = Products::find($id);
        if ($student) {
            $student->delete();
            return new ProductsResource('success', 'Product deleted successfully', null);
        } else {
            return new ProductsResource('error', 'Product not found', null);
        }
    }
}
