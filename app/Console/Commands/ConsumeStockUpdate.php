<?php

namespace App\Console\Commands;

use App\Services\RabbitMQConsumer;
use Illuminate\Console\Command;

class ConsumeStockUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rabbitmq:consume-stock {--loop : Keep running and consuming continuously} {--interval=5 : Seconds between loops}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Consume stock update messages from RabbitMQ queue';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting RabbitMQ Stock Update Consumer...');
        $this->info('Queue: ' . config('services.rabbitmq.queue', 'stock_update_queue'));

        $consumer = new RabbitMQConsumer();

        if ($this->option('loop')) {
            $interval = (int) $this->option('interval');
            $this->info("Running in loop mode with {$interval}s interval. Press Ctrl+C to stop.");

            while (true) {
                $this->consumeMessages($consumer);
                sleep($interval);
            }
        } else {
            $this->consumeMessages($consumer);
        }
    }

    /**
     * Consume and process messages
     */
    private function consumeMessages(RabbitMQConsumer $consumer)
    {
        $results = $consumer->processStockUpdate();

        if (empty($results)) {
            $this->info('No messages in queue.');
            return;
        }

        $this->info("Processed " . count($results) . " message(s):");

        foreach ($results as $result) {
            if ($result['success']) {
                $this->info("✓ Product {$result['product_id']}: Stock updated (new stock: {$result['new_stock']})");
            } else {
                $this->error("✗ Product {$result['product_id']}: {$result['error']}");
            }
        }
    }
}
