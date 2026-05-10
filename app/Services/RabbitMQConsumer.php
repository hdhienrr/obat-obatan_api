<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RabbitMQConsumer
{
    private string $host;
    private string $port;
    private string $user;
    private string $password;
    private string $vhost;
    private string $queue;

    public function __construct()
    {
        $this->host = config('services.rabbitmq.host', 'host.docker.internal');
        $this->port = config('services.rabbitmq.port', '15672');
        $this->user = config('services.rabbitmq.user', 'guest');
        $this->password = config('services.rabbitmq.password', 'guest');
        $this->vhost = config('services.rabbitmq.vhost', '%2F');
        $this->queue = config('services.rabbitmq.queue', 'stock_update_queue');
    }

    /**
     * Get messages from RabbitMQ queue via HTTP API
     */
    public function consume(int $count = 1): array
    {
        $url = "http://{$this->host}:{$this->port}/api/queues/{$this->vhost}/{$this->queue}/get";

        $payload = [
            'count' => $count,
            'ackmode' => 'ack_requeue_false',
            'encoding' => 'auto',
        ];

        try {
            $response = Http::withBasicAuth($this->user, $this->password)
                ->timeout(10)
                ->post($url, $payload);

            if ($response->successful()) {
                $messages = $response->json();
                return is_array($messages) ? $messages : [];
            }

            Log::error('RabbitMQ consume failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];
        } catch (\Exception $e) {
            Log::error('RabbitMQ consume exception', [
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Process stock update message
     */
    public function processStockUpdate(): array
    {
        $messages = $this->consume(10); // Get up to 10 messages
        $results = [];

        foreach ($messages as $message) {
            if (!isset($message['payload'])) {
                continue;
            }

            $data = json_decode($message['payload'], true);

            if (!$data || !isset($data['action'])) {
                continue;
            }

            try {
                if ($data['action'] === 'decrease_stock') {
                    $result = $this->decreaseStock($data);
                    $results[] = $result;

                    Log::info('Stock updated via RabbitMQ', [
                        'product_id' => $data['product_id'],
                        'quantity' => $data['quantity'],
                        'success' => $result['success'],
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Failed to process stock update message', [
                    'message' => $e->getMessage(),
                    'data' => $data,
                ]);

                $results[] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'data' => $data,
                ];
            }
        }

        return $results;
    }

    /**
     * Decrease product stock
     */
    private function decreaseStock(array $data): array
    {
        $productId = $data['product_id'];
        $quantity = $data['quantity'];

        // Find product
        $product = \App\Models\Obat::find($productId);

        if (!$product) {
            return [
                'success' => false,
                'error' => 'Product not found',
                'product_id' => $productId,
            ];
        }

        // Check if stock is sufficient
        if ($product->stock < $quantity) {
            return [
                'success' => false,
                'error' => 'Insufficient stock',
                'product_id' => $productId,
                'current_stock' => $product->stock,
                'requested' => $quantity,
            ];
        }

        // Update stock
        $product->stock -= $quantity;
        $product->save();

        return [
            'success' => true,
            'product_id' => $productId,
            'new_stock' => $product->stock,
            'quantity_decreased' => $quantity,
        ];
    }
}
