<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 补证申请与办理进度。
 * 仅本人可创建 / 查询；补证完成时在同一事务内把旧证置为 replaced、
 * 签发新 active 证，保证“补证完成后旧证号永久失效”。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('replacement_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lost_card_id')->constrained('student_cards')->cascadeOnDelete();
            $table->foreignId('new_card_id')->nullable()->constrained('student_cards')->nullOnDelete();
            // pending=待受理  processing=制证中  completed=已完成  rejected=已驳回
            $table->string('status', 16)->default('pending');
            $table->string('reason')->default('证件遗失')->comment('申请理由');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('replacement_applications');
    }
};
