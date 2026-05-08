<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('payments', function (Blueprint $table) {
                $table->unsignedBigInteger('rental_id')->nullable()->change();
            });

            return;
        }

        DB::statement('ALTER TABLE payments DROP FOREIGN KEY payments_rental_id_foreign');
        DB::statement('ALTER TABLE payments MODIFY rental_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_rental_id_foreign FOREIGN KEY (rental_id) REFERENCES rentals(id) ON DELETE SET NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('DELETE FROM payments WHERE rental_id IS NULL');
        DB::statement('ALTER TABLE payments DROP FOREIGN KEY payments_rental_id_foreign');
        DB::statement('ALTER TABLE payments MODIFY rental_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_rental_id_foreign FOREIGN KEY (rental_id) REFERENCES rentals(id) ON DELETE CASCADE');
    }
};
