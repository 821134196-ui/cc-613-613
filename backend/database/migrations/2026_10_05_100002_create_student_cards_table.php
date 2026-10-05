<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 学生证实体（物理证件）。
 * 一个学生可对应多张历史证件；旧证挂失后状态永久不可恢复，
 * 补证时旧证 -> replaced，新证 -> active。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('card_no', 40)->unique()->comment('学生证号（补证换号）');
            $table->unsignedInteger('version')->default(1)->comment('第几版证件');
            // active=有效  lost=已挂失  replaced=已被新证替代（旧证永久失效）
            $table->string('status', 16)->default('active');
            $table->uuid('qr_token')->unique()->comment('扫码核验令牌（窗口只拿到这个）');
            $table->timestamp('lost_at')->nullable()->comment('挂失时间（一旦写入不可逆）');
            $table->timestamp('replaced_at')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_cards');
    }
};
