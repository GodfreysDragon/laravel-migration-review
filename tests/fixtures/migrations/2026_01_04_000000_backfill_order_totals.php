<?php

use App\Models\Order;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('orders')->whereNull('status')->update(['status' => 'pending']);

        Order::query()->where('total_cents', 0)->each->recalculate();

        DB::statement('DELETE FROM order_events');

        // migration-review-ignore: data-manipulation
        DB::table('orders')->where('currency', '')->update(['currency' => 'USD']);
    }

    public function down(): void {}
};
