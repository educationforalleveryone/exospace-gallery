<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    /**
     * Handle 2Checkout IPN (Instant Payment Notification)
     * 
     * 2Checkout Documentation: https://www.2checkout.com/documentation/notifications/ins
     */
    public function handle2Checkout(Request $request)
    {
        // Log the incoming webhook for debugging
        Log::info('2Checkout Webhook Received', [
            'payload' => $request->all(),
            'ip' => $request->ip()
        ]);

        // ================================
        // STEP 1: Security Verification
        // ================================
        
        // Get the secret word from your .env file
        $secretWord = config('services.2checkout.secret_word');
        
        if (!$secretWord) {
            Log::error('2Checkout: SECRET_WORD not configured in .env');
            return response('Secret word not configured', 500);
        }

        // 2Checkout sends a hash we need to verify
        $receivedHash = $request->input('md5_hash');
        
        // Build our own hash to compare
        $stringToHash = strlen($request->input('sale_id')) . 
                        $request->input('sale_id') . 
                        strlen($request->input('vendor_id')) . 
                        $request->input('vendor_id') . 
                        strlen($request->input('invoice_id')) . 
                        $request->input('invoice_id') . 
                        strlen($secretWord) . 
                        $secretWord;
        
        $calculatedHash = strtoupper(md5($stringToHash));
        
        // Verify the hash matches
        if ($calculatedHash !== strtoupper($receivedHash)) {
            Log::warning('2Checkout: Hash verification failed', [
                'received' => $receivedHash,
                'calculated' => $calculatedHash
            ]);
            return response('Hash verification failed', 403);
        }

        // ================================
        // STEP 2: Process the Order
        // ================================
        
        $messageType = $request->input('message_type');
        
        // We only care about successful orders
        if ($messageType !== 'ORDER_CREATED') {
            Log::info('2Checkout: Ignoring message type', ['type' => $messageType]);
            return response('OK', 200);
        }

        // Extract customer info
        $customerEmail = $request->input('customer_email');
        $customerName = $request->input('customer_name');
        $invoiceId = $request->input('invoice_id');
        $productId = $request->input('item_id_1'); // Product ID from 2Checkout
        
        // Find the user by email
        $user = User::where('email', $customerEmail)->first();
        
        if (!$user) {
            Log::warning('2Checkout: User not found', ['email' => $customerEmail]);
            return response('User not found', 404);
        }

        // ================================
        // STEP 3: Upgrade the User
        // ================================
        
        // Determine which plan based on product ID or price
        // You can customize this based on your 2Checkout product setup
        $plan = 'pro'; // Default to Pro for now
        $maxGalleries = 999;
        $maxImages = 100;
        
        // Update the user's plan
        $user->update([
            'plan' => $plan,
            'max_galleries' => $maxGalleries,
            'max_images' => $maxImages,
            'plan_started_at' => now(),
            'plan_expires_at' => null, // Lifetime access
        ]);

        Log::info('2Checkout: User upgraded successfully', [
            'user_id' => $user->id,
            'email' => $user->email,
            'plan' => $plan,
            'invoice_id' => $invoiceId
        ]);

        // ================================
        // STEP 4: Optional - Store Transaction
        // ================================
        
        // If you want to track purchases, you can store them in a 'transactions' table
        // For now, we'll just log it
        
        return response('Webhook processed successfully', 200);
    }

    /**
     * Handle refunds and cancellations
     */
    public function handleRefund(Request $request)
    {
        Log::info('2Checkout Refund Received', $request->all());
        
        // Verify hash (same process as above)
        $secretWord = config('services.2checkout.secret_word');
        $receivedHash = $request->input('md5_hash');
        
        // Build hash verification
        $stringToHash = strlen($request->input('sale_id')) . 
                        $request->input('sale_id') . 
                        strlen($request->input('vendor_id')) . 
                        $request->input('vendor_id') . 
                        strlen($request->input('invoice_id')) . 
                        $request->input('invoice_id') . 
                        strlen($secretWord) . 
                        $secretWord;
        
        $calculatedHash = strtoupper(md5($stringToHash));
        
        if ($calculatedHash !== strtoupper($receivedHash)) {
            return response('Hash verification failed', 403);
        }

        // Downgrade user back to free
        $customerEmail = $request->input('customer_email');
        $user = User::where('email', $customerEmail)->first();
        
        if ($user && $user->plan === 'pro') {
            $user->update([
                'plan' => 'free',
                'max_galleries' => 1,
                'max_images' => 10,
                'plan_expires_at' => now(),
            ]);
            
            Log::info('2Checkout: User downgraded after refund', [
                'user_id' => $user->id,
                'email' => $user->email
            ]);
        }
        
        return response('Refund processed', 200);
    }
}