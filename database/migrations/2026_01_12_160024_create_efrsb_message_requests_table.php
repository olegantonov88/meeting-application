<?php

use App\Enums\EfrsbMessageRequestStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('efrsb_message_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('meeting_application_id');
            $table->unsignedBigInteger('message_id');
            $table->timestamp('requested_at');
            $table->tinyInteger('status')->default(EfrsbMessageRequestStatus::PENDING->value);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['meeting_application_id', 'status']);
            $table->index(['message_id']);
            $table->index(['requested_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('efrsb_message_requests');
    }
};
