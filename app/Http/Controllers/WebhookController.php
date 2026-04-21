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
        // STEP 3: Map Product ID → Plan
        // ================================

        // Map your 2Checkout Product IDs to plans
        // After creating products in 2Checkout, paste the numeric Product IDs here
        $productMap = [
            config('services.2checkout.product_id_pro')    => [
                'plan'           => 'pro',
                'max_galleries'  => 5,
                'max_images'     => 50,
            ],
            config('services.2checkout.product_id_studio') => [
                'plan'           => 'studio',
                'max_galleries'  => 999,
                'max_images'     => 999,
            ],
        ];

        $planConfig = $productMap[$productId] ?? null;

        if (!$planConfig) {
            Log::warning('2Checkout: Unknown product ID received', [
                'product_id' => $productId,
                'email'      => $customerEmail,
                'invoice_id' => $invoiceId,
            ]);
            // Still return 200 so 2Checkout doesn't keep retrying,
            // but flag it for manual review
            return response('Unknown product - flagged for review', 200);
        }

        // ================================
        // STEP 4: Upgrade the User
        // ================================

        $user->update([
            'plan'            => $planConfig['plan'],
            'max_galleries'   => $planConfig['max_galleries'],
            'max_images'      => $planConfig['max_images'],
            'plan_started_at' => now(),
            'plan_expires_at' => null, // Lifetime / one-time purchase
        ]);

        // ================================
        // STEP 5: Store Transaction Record
        // ================================

        \DB::table('transactions')->insert([
            'user_id'        => $user->id,
            'invoice_id'     => $invoiceId,
            'sale_id'        => $request->input('sale_id'),
            'product_id'     => $productId,
            'plan'           => $planConfig['plan'],
            'amount'         => $request->input('item_list_amount_1', 0),
            'currency'       => $request->input('list_currency', 'USD'),
            'customer_email' => $customerEmail,
            'customer_name'  => $customerName,
            'status'         => 'completed',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        Log::info('2Checkout: User upgraded successfully', [
            'user_id'    => $user->id,
            'email'      => $user->email,
            'plan'       => $planConfig['plan'],
            'invoice_id' => $invoiceId,
        ]);

        return response('OK', 200);
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
        
        if ($user && in_array($user->plan, ['pro', 'studio'])) {
            $user->update([
                'plan'            => 'free',
                'max_galleries'   => 1,
                'max_images'      => 10,
                'plan_expires_at' => now(),
            ]);

            \DB::table('transactions')
                ->where('customer_email', $customerEmail)
                ->latest('created_at')
                ->limit(1)
                ->update(['status' => 'refunded', 'updated_at' => now()]);
            
            Log::info('2Checkout: User downgraded after refund', [
                'user_id' => $user->id,
                'email' => $user->email
            ]);
        }
        
        return response('Refund processed', 200);
    }
}