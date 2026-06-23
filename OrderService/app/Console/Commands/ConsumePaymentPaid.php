<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use App\Models\Order;

class ConsumePaymentPaid extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rabbitmq:consume-payment-paid';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mendengarkan event payment-paid dari RabbitMQ dan mengupdate status order';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Menghubungkan ke RabbitMQ...");

        try {
            $connection = new AMQPStreamConnection(
                env('RABBITMQ_HOST', 'rabbitmq'),
                env('RABBITMQ_PORT', 5672),
                env('RABBITMQ_USER', 'guest'),
                env('RABBITMQ_PASSWORD', 'guest')
            );
        } catch (\Exception $e) {
            $this->error("Gagal terhubung ke RabbitMQ: " . $e->getMessage());
            return;
        }

        $channel = $connection->channel();
        $queue = env('RABBITMQ_QUEUE', 'payment-paid');

        // Deklarasi queue agar jika belum ada otomatis dibuat
        $channel->queue_declare($queue, false, true, false, false);

        $this->info(" [*] Menunggu pesan di queue '{$queue}'. Tekan CTRL+C untuk keluar.");

        $callback = function ($msg) {
            $payload = json_decode($msg->body, true);
            
            $this->info(" [x] Menerima pesan: " . $msg->body);

            if (isset($payload['event']) && $payload['event'] === 'payment.paid' && isset($payload['order_id'])) {
                $order = Order::find($payload['order_id']);
                
                if ($order) {
                    if ($order->status !== 'paid') {
                        $order->update(['status' => 'paid']);
                        $this->info(" [v] Berhasil! Status Order ID {$order->id} telah diubah menjadi 'paid'.");
                    } else {
                        $this->warn(" [!] Order ID {$order->id} sudah berstatus paid.");
                    }
                } else {
                    $this->error(" [X] Order ID {$payload['order_id']} tidak ditemukan di database!");
                }
            } else {
                $this->error(" [X] Format pesan tidak sesuai atau bukan event payment.paid.");
            }

            // Ack message agar terhapus dari antrean
            $msg->ack();
        };

        // QOS 1 agar mendapatkan pesan satu per satu
        $channel->basic_qos(null, 1, null);
        $channel->basic_consume($queue, '', false, false, false, false, $callback);

        try {
            while ($channel->is_consuming()) {
                $channel->wait();
            }
        } catch (\Throwable $exception) {
            $this->error("Terjadi error: " . $exception->getMessage());
        }

        $channel->close();
        $connection->close();
    }
}
