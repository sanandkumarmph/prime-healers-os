<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addReferralColumnIfMissing('rentals', 'referral_contact', 180);
        $this->addReferralColumnIfMissing('rentals', 'referral_city', 120);

        $this->addReferralColumnIfMissing('sales', 'referral_contact', 180);
        $this->addReferralColumnIfMissing('sales', 'referral_city', 120);
    }

    public function down(): void
    {
        $this->dropReferralColumnIfExists('sales', 'referral_city');
        $this->dropReferralColumnIfExists('sales', 'referral_contact');

        $this->dropReferralColumnIfExists('rentals', 'referral_city');
        $this->dropReferralColumnIfExists('rentals', 'referral_contact');
    }

    private function addReferralColumnIfMissing(string $tableName, string $columnName, int $length): void
    {
        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, $columnName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columnName, $length) {
            $table->string($columnName, $length)->nullable();
        });
    }

    private function dropReferralColumnIfExists(string $tableName, string $columnName): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, $columnName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columnName) {
            $table->dropColumn($columnName);
        });
    }
};
